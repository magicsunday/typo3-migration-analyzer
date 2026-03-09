<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\AutomationGrade;
use App\Dto\LlmAnalysisResult;
use App\Dto\LlmCodeMapping;
use App\Dto\LlmRectorAssessment;
use App\Dto\RstDocument;
use App\Llm\LlmClientFactory;
use App\Llm\LlmResponse;
use App\Repository\LlmResultRepository;
use JsonException;

use function array_intersect;
use function count;
use function date;
use function implode;
use function is_array;
use function json_decode;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function round;
use function sprintf;

/**
 * Orchestrates LLM-based analysis of RST documents.
 *
 * Checks the cache first, calls the configured LLM provider if needed,
 * parses the JSON response, and persists the result.
 */
final readonly class LlmAnalysisService
{
    public function __construct(
        private LlmClientFactory $clientFactory,
        private LlmResultRepository $repository,
        private LlmConfigurationService $configService,
    ) {
    }

    /**
     * Analyze a single document, using cached results if available.
     */
    public function analyze(RstDocument $document, bool $forceReanalyze = false): ?LlmAnalysisResult
    {
        if (!$this->configService->isConfigured()) {
            return null;
        }

        $config        = $this->configService->load();
        $promptVersion = $config->promptVersion;

        // Check cache unless forced
        if (!$forceReanalyze) {
            $cached = $this->repository->find($document->filename, $config->modelId, $promptVersion);

            if ($cached instanceof LlmAnalysisResult) {
                return $cached;
            }
        }

        // Build prompt with document context
        $userPrompt = $this->buildUserPrompt($document);

        // Call LLM
        $client   = $this->clientFactory->create($config->provider, $config->apiKey);
        $response = $client->analyze($config->analysisPrompt, $userPrompt, $config->modelId);

        // Parse JSON response and persist
        $result = $this->parseResponse($response, $document->filename, $config->modelId, $promptVersion);
        $this->repository->save($result);

        return $result;
    }

    /**
     * Get cached result for the current model and prompt version.
     */
    public function getCachedResult(string $filename): ?LlmAnalysisResult
    {
        $config = $this->configService->load();

        return $this->repository->find($filename, $config->modelId, $config->promptVersion);
    }

    /**
     * Get the most recent cached result regardless of model or prompt version.
     */
    public function getLatestResult(string $filename): ?LlmAnalysisResult
    {
        return $this->repository->findLatest($filename);
    }

    /**
     * Get all filenames that have been analyzed with the current prompt version.
     *
     * @return list<string>
     */
    public function getAnalyzedFilenames(): array
    {
        return $this->repository->getAnalyzedFilenames($this->configService->load()->promptVersion);
    }

    /**
     * Get total token usage for the current prompt version.
     *
     * @return array{input: int, output: int}
     */
    public function getTotalTokens(): array
    {
        return $this->repository->getTotalTokens($this->configService->load()->promptVersion);
    }

    /**
     * Returns analysis progress for the current prompt version within the given document set.
     *
     * Only counts documents that exist in the provided filename list, ensuring the progress
     * reflects the active version range rather than all analyzed documents across all versions.
     *
     * @param string[] $documentFilenames Filenames of documents in the current version range
     *
     * @return array{analyzed: int, total: int, percent: float}
     */
    public function getProgress(array $documentFilenames): array
    {
        $config           = $this->configService->load();
        $analyzedNames    = $this->repository->getAnalyzedFilenames($config->promptVersion);
        $relevantAnalyzed = count(array_intersect($analyzedNames, $documentFilenames));
        $total            = count($documentFilenames);

        return [
            'analyzed' => $relevantAnalyzed,
            'total'    => $total,
            'percent'  => $total > 0
                ? round($relevantAnalyzed / $total * 100, 1)
                : 0.0,
        ];
    }

    /**
     * Build the user prompt from the RST document content.
     */
    public function buildUserPrompt(RstDocument $document): string
    {
        $parts = [
            'Document: ' . $document->filename,
            'Type: ' . $document->type->value,
            'Version: ' . $document->version,
            'Title: ' . $document->title,
            '',
            '## Description',
            $document->description,
        ];

        if ($document->impact !== null && $document->impact !== '') {
            $parts[] = '';
            $parts[] = '## Impact';
            $parts[] = $document->impact;
        }

        if ($document->migration !== null && $document->migration !== '') {
            $parts[] = '';
            $parts[] = '## Migration';
            $parts[] = $document->migration;
        }

        if ($document->codeBlocks !== []) {
            $parts[] = '';
            $parts[] = '## Code Examples';

            foreach ($document->codeBlocks as $block) {
                $label   = ($block->label !== null && $block->label !== '') ? sprintf(' (%s)', $block->label) : '';
                $parts[] = sprintf('```%s%s', $block->language, $label);
                $parts[] = $block->code;
                $parts[] = '```';
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Parse the LLM JSON response into an LlmAnalysisResult.
     */
    private function parseResponse(
        LlmResponse $response,
        string $filename,
        string $modelId,
        string $promptVersion,
    ): LlmAnalysisResult {
        // Sanitize control characters that LLMs may produce unescaped inside JSON strings
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $response->content) ?? $response->content;

        /** @var array{score?: int, automation_grade?: string, summary?: string, reasoning?: string, migration_steps?: list<string|array<string, mixed>>, affected_areas?: list<string|array<string, mixed>>, affected_components?: list<string|array<string, mixed>>, code_mappings?: list<mixed>, rector_assessment?: array{feasible?: bool, rule_type?: string|null, notes?: string}|null} $data */
        $data = $this->decodeJson($sanitized, $filename);

        $gradeValue = $data['automation_grade'] ?? 'manual';

        // Parse code_mappings
        $rawMappings  = $data['code_mappings'] ?? [];
        $codeMappings = [];

        foreach ($rawMappings as $mapping) {
            if (is_array($mapping)) {
                /** @var array{old?: string, new?: string|null, type?: string} $mapping */
                $codeMappings[] = new LlmCodeMapping(
                    $mapping['old'] ?? '',
                    $mapping['new'] ?? null,
                    $mapping['type'] ?? 'behavior_change',
                );
            }
        }

        // Parse rector_assessment
        $rectorAssessment = null;
        $rawAssessment    = $data['rector_assessment'] ?? null;

        if (is_array($rawAssessment)) {
            $rectorAssessment = new LlmRectorAssessment(
                feasible: (bool) ($rawAssessment['feasible'] ?? false),
                ruleType: $rawAssessment['rule_type'] ?? null,
                notes: (string) ($rawAssessment['notes'] ?? ''),
            );
        }

        return new LlmAnalysisResult(
            filename: $filename,
            modelId: $modelId,
            promptVersion: $promptVersion,
            score: $data['score'] ?? 3,
            automationGrade: AutomationGrade::tryFrom($gradeValue) ?? AutomationGrade::Manual,
            summary: $data['summary'] ?? '',
            reasoning: $data['reasoning'] ?? '',
            migrationSteps: LlmAnalysisResult::normalizeToStrings($data['migration_steps'] ?? []),
            affectedAreas: LlmAnalysisResult::normalizeToStrings($data['affected_areas'] ?? []),
            affectedComponents: LlmAnalysisResult::normalizeToStrings($data['affected_components'] ?? []),
            codeMappings: $codeMappings,
            rectorAssessment: $rectorAssessment,
            tokensInput: $response->inputTokens,
            tokensOutput: $response->outputTokens,
            durationMs: $response->durationMs,
            createdAt: date('Y-m-d H:i:s'),
        );
    }

    /**
     * Decode JSON from LLM output, with fallback extraction and descriptive errors.
     *
     * LLMs occasionally wrap their JSON in explanatory text. This method first tries
     * a direct decode, then falls back to extracting the first JSON object from the
     * response. On failure, the exception message includes the filename and a preview
     * of the raw content.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $content, string $filename): array
    {
        // Try direct decode first
        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true, 512);

        if (is_array($data)) {
            return $data;
        }

        // Fallback: extract JSON object from surrounding text
        if (preg_match('/\{(?:[^{}]|(?:\{[^{}]*\}))*\}/s', $content, $matches) === 1) {
            /** @var array<string, mixed>|null $extracted */
            $extracted = json_decode($matches[0], true, 512);

            if (is_array($extracted)) {
                return $extracted;
            }
        }

        $preview = mb_substr($content, 0, 200);

        throw new JsonException(sprintf(
            'Invalid JSON from LLM for "%s": %s — Response preview: %s',
            $filename,
            json_last_error_msg(),
            $preview,
        ));
    }
}
