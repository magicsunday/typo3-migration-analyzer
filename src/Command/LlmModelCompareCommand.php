<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Command;

use App\Dto\RstDocument;
use App\Dto\VersionRange;
use App\Llm\LlmClientFactory;
use App\Service\DocumentService;
use App\Service\LlmAnalysisService;
use App\Service\LlmConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_count_values;
use function array_rand;
use function array_sum;
use function array_unique;
use function count;
use function implode;
use function is_array;
use function json_decode;
use function max;
use function min;
use function number_format;
use function preg_replace;
use function round;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function usleep;

/**
 * Compares LLM analysis results across multiple Claude models for the same RST documents.
 *
 * Picks random documents, sends them to each model, and produces a side-by-side comparison
 * of scores, automation grades, summaries, and token usage.
 */
#[AsCommand(
    name: 'llm:compare',
    description: 'Compare LLM analysis across multiple Claude models',
)]
final class LlmModelCompareCommand extends Command
{
    /** @var list<string> */
    private const array DEFAULT_MODELS = [
        'claude-haiku-4-5-20251001',
        'claude-sonnet-4-6',
        'claude-opus-4-6',
    ];

    /** @var array<string, string> */
    private const array MODEL_LABELS = [
        'claude-haiku-4-5-20251001' => 'Haiku 4.5',
        'claude-sonnet-4-6'         => 'Sonnet 4.6',
        'claude-opus-4-6'           => 'Opus 4.6',
    ];

    /** @var array<string, array{float, float}> */
    private const array PRICING_MAP = [
        'claude-haiku-4-5-20251001' => [1.00, 5.00],
        'claude-sonnet-4-6'         => [3.00, 15.00],
        'claude-opus-4-6'           => [5.00, 25.00],
    ];

    public function __construct(
        private readonly DocumentService $documentService,
        private readonly LlmConfigurationService $configService,
        private readonly LlmClientFactory $clientFactory,
        private readonly LlmAnalysisService $analysisService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of random documents to compare', '10')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source TYPO3 major version', '12')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Target TYPO3 major version', '13');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->configService->isConfigured()) {
            $io->error('LLM is not configured. Set an API key first.');

            return Command::FAILURE;
        }

        $config = $this->configService->load();

        /** @var string $countOption */
        $countOption = $input->getOption('count');

        /** @var string $sourceOption */
        $sourceOption = $input->getOption('source');

        /** @var string $targetOption */
        $targetOption = $input->getOption('target');

        $count         = (int) $countOption;
        $sourceVersion = (int) $sourceOption;
        $targetVersion = (int) $targetOption;

        $this->documentService->setVersionRange(new VersionRange($sourceVersion, $targetVersion));
        $documents = $this->documentService->getDocuments();

        if ($documents === []) {
            $io->error(sprintf('No documents found for TYPO3 %d → %d migration.', $sourceVersion, $targetVersion));

            return Command::FAILURE;
        }

        $io->title(sprintf('LLM Model Comparison: TYPO3 %d → %d', $sourceVersion, $targetVersion));
        $io->text(sprintf('Documents available: %d', count($documents)));
        $io->text(sprintf('Sampling: %d random documents', $count));
        $io->text(sprintf('Models: %s', implode(', ', self::DEFAULT_MODELS)));
        $io->newLine();

        $sampleDocs = $this->pickRandomDocuments($documents, $count);

        $io->section('Selected documents');

        foreach ($sampleDocs as $i => $doc) {
            $io->text(sprintf('  %2d. %s', $i + 1, $doc->filename));
        }

        $io->newLine();

        $client     = $this->clientFactory->create($config->provider, $config->apiKey);
        $totalCalls = count($sampleDocs) * count(self::DEFAULT_MODELS);

        /** @var array<string, array<string, array{score: int, grade: string, summary: string, reasoning: string, tokens_in: int, tokens_out: int, duration: int}|null>> $results */
        $results     = [];
        $currentCall = 0;

        foreach ($sampleDocs as $doc) {
            $userPrompt = $this->analysisService->buildUserPrompt($doc);

            foreach (self::DEFAULT_MODELS as $modelId) {
                ++$currentCall;
                $label = self::MODEL_LABELS[$modelId];
                $io->text(sprintf('[%d/%d] %s → %s ...', $currentCall, $totalCalls, $label, $doc->filename));

                try {
                    $response  = $client->analyze($config->analysisPrompt, $userPrompt, $modelId);
                    $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $response->content) ?? $response->content;

                    /** @var array{score?: int, automation_grade?: string, summary?: string, reasoning?: string} $parsed */
                    $parsed = json_decode($sanitized, true, 512, JSON_THROW_ON_ERROR);

                    $results[$doc->filename][$modelId] = [
                        'score'      => (int) ($parsed['score'] ?? 0),
                        'grade'      => (string) ($parsed['automation_grade'] ?? 'unknown'),
                        'summary'    => (string) ($parsed['summary'] ?? ''),
                        'reasoning'  => (string) ($parsed['reasoning'] ?? ''),
                        'tokens_in'  => $response->inputTokens,
                        'tokens_out' => $response->outputTokens,
                        'duration'   => $response->durationMs,
                    ];
                } catch (Throwable $e) {
                    $results[$doc->filename][$modelId] = null;
                    $io->warning(sprintf('  Error: %s', $e->getMessage()));
                }

                // Rate limiting between API calls
                usleep(500_000);
            }
        }

        $io->newLine();

        $this->renderComparison($io, $results);
        $this->renderAggregateStats($io, $results);

        return Command::SUCCESS;
    }

    /**
     * Pick N random documents from the list.
     *
     * @param RstDocument[] $documents
     *
     * @return RstDocument[]
     */
    private function pickRandomDocuments(array $documents, int $count): array
    {
        $count = min($count, count($documents));
        $keys  = array_rand($documents, $count);

        if (!is_array($keys)) {
            $keys = [$keys];
        }

        $picked = [];

        foreach ($keys as $key) {
            $picked[] = $documents[$key];
        }

        return $picked;
    }

    /**
     * Render per-document comparison tables.
     *
     * @param array<string, array<string, array{score: int, grade: string, summary: string, reasoning: string, tokens_in: int, tokens_out: int, duration: int}|null>> $results
     */
    private function renderComparison(SymfonyStyle $io, array $results): void
    {
        $io->section('Per-Document Comparison');

        $docNumber = 0;

        foreach ($results as $filename => $modelResults) {
            ++$docNumber;
            $io->text(sprintf('<info>%d. %s</info>', $docNumber, $filename));
            $io->newLine();

            $headerLine = sprintf('  %-14s │ %-5s │ %-9s │ %-8s │ %-8s │ %-6s │ %s', 'Model', 'Score', 'Grade', 'Tok In', 'Tok Out', 'Ms', 'Summary');
            $io->text($headerLine);
            $io->text('  ' . str_repeat('─', 120));

            foreach (self::DEFAULT_MODELS as $modelId) {
                $label = self::MODEL_LABELS[$modelId];
                $data  = $modelResults[$modelId] ?? null;

                if ($data === null) {
                    $io->text(sprintf('  %-14s │ ERROR', $label));

                    continue;
                }

                $summary = $data['summary'];

                if (strlen($summary) > 60) {
                    $summary = substr($summary, 0, 57) . '...';
                }

                $io->text(sprintf(
                    '  %-14s │ %5d │ %-9s │ %8s │ %8s │ %6s │ %s',
                    $label,
                    $data['score'],
                    $data['grade'],
                    number_format($data['tokens_in']),
                    number_format($data['tokens_out']),
                    number_format($data['duration']),
                    $summary,
                ));
            }

            // Collect scores and grades for agreement check
            $scores = [];
            $grades = [];

            foreach (self::DEFAULT_MODELS as $modelId) {
                $data = $modelResults[$modelId] ?? null;

                if ($data !== null) {
                    $scores[] = $data['score'];
                    $grades[] = $data['grade'];
                }
            }

            $scoreAgree = count(array_unique($scores)) === 1;
            $gradeAgree = count(array_unique($grades)) === 1;

            $io->newLine();

            if ($scoreAgree && $gradeAgree) {
                $io->text('  <fg=green>✓ All models agree on score and grade</>');
            } else {
                if (!$scoreAgree) {
                    $io->text(sprintf('  <fg=yellow>⚠ Score divergence: %s</>', implode(' vs ', $scores)));
                }

                if (!$gradeAgree) {
                    $io->text(sprintf('  <fg=yellow>⚠ Grade divergence: %s</>', implode(' vs ', $grades)));
                }
            }

            $io->newLine();
            $io->text('  <fg=cyan>Reasoning:</>');

            foreach (self::DEFAULT_MODELS as $modelId) {
                $label = self::MODEL_LABELS[$modelId];
                $data  = $modelResults[$modelId] ?? null;

                if ($data === null) {
                    continue;
                }

                $io->text(sprintf('    %s: %s', $label, $data['reasoning']));
            }

            $io->newLine();
            $io->text(str_repeat('═', 130));
            $io->newLine();
        }
    }

    /**
     * Render aggregate statistics across all documents.
     *
     * @param array<string, array<string, array{score: int, grade: string, summary: string, reasoning: string, tokens_in: int, tokens_out: int, duration: int}|null>> $results
     */
    private function renderAggregateStats(SymfonyStyle $io, array $results): void
    {
        $io->section('Aggregate Statistics');

        $stats = $this->collectModelStats($results);

        $io->text(sprintf(
            '  %-14s │ %9s │ %9s │ %9s │ %-20s │ %10s │ %10s │ %8s │ %s',
            'Model',
            'Avg Score',
            'Min Score',
            'Max Score',
            'Grade Distribution',
            'Tokens In',
            'Tokens Out',
            'Avg Ms',
            'Errors',
        ));
        $io->text('  ' . str_repeat('─', 130));

        foreach (self::DEFAULT_MODELS as $modelId) {
            $label  = self::MODEL_LABELS[$modelId];
            $s      = $stats[$modelId];
            $scores = $s['scores'];

            if ($scores === []) {
                $io->text(sprintf('  %-14s │ no results', $label));

                continue;
            }

            $avgScore = round(array_sum($scores) / count($scores), 1);
            $minScore = min($scores);
            $maxScore = max($scores);
            $avgMs    = (int) round($s['duration'] / count($scores));

            $gradeCounts = array_count_values($s['grades']);
            $gradeParts  = [];

            foreach (['full', 'partial', 'manual'] as $grade) {
                if (isset($gradeCounts[$grade])) {
                    $gradeParts[] = sprintf('%s:%d', $grade[0], $gradeCounts[$grade]);
                }
            }

            $io->text(sprintf(
                '  %-14s │ %9s │ %9d │ %9d │ %-20s │ %10s │ %10s │ %8s │ %d',
                $label,
                $avgScore,
                $minScore,
                $maxScore,
                implode(' ', $gradeParts),
                number_format($s['tokens_in']),
                number_format($s['tokens_out']),
                number_format($avgMs),
                $s['errors'],
            ));
        }

        $io->newLine();

        // Agreement analysis
        $totalDocs  = count($results);
        $scoreAgree = 0;
        $gradeAgree = 0;
        $fullAgree  = 0;
        $scoreDiffs = [];

        foreach ($results as $modelResults) {
            $scores = [];
            $grades = [];

            foreach (self::DEFAULT_MODELS as $modelId) {
                $data = $modelResults[$modelId] ?? null;

                if ($data !== null) {
                    $scores[] = $data['score'];
                    $grades[] = $data['grade'];
                }
            }

            if (count($scores) === count(self::DEFAULT_MODELS)) {
                if (count(array_unique($scores)) === 1) {
                    ++$scoreAgree;
                }

                if (count(array_unique($grades)) === 1) {
                    ++$gradeAgree;
                }

                if (count(array_unique($scores)) === 1 && count(array_unique($grades)) === 1) {
                    ++$fullAgree;
                }

                $scoreDiffs[] = max($scores) - min($scores);
            }
        }

        $io->text('<info>Agreement Analysis:</info>');
        $io->text(sprintf('  Score agreement (all 3 identical):   %d/%d (%.0f%%)', $scoreAgree, $totalDocs, $totalDocs > 0 ? $scoreAgree / $totalDocs * 100 : 0));
        $io->text(sprintf('  Grade agreement (all 3 identical):   %d/%d (%.0f%%)', $gradeAgree, $totalDocs, $totalDocs > 0 ? $gradeAgree / $totalDocs * 100 : 0));
        $io->text(sprintf('  Full agreement (score + grade):      %d/%d (%.0f%%)', $fullAgree, $totalDocs, $totalDocs > 0 ? $fullAgree / $totalDocs * 100 : 0));

        if ($scoreDiffs !== []) {
            $avgDiff = round(array_sum($scoreDiffs) / count($scoreDiffs), 2);
            $maxDiff = max($scoreDiffs);
            $io->text(sprintf('  Average score spread:                %.2f', $avgDiff));
            $io->text(sprintf('  Max score spread:                    %d', $maxDiff));
        }

        $io->newLine();

        // Cost estimate
        $io->text('<info>Cost Estimate:</info>');

        $totalCost = 0.0;

        foreach (self::DEFAULT_MODELS as $modelId) {
            $label   = self::MODEL_LABELS[$modelId];
            $s       = $stats[$modelId];
            $pricing = self::PRICING_MAP[$modelId];
            $cost    = ($s['tokens_in'] / 1_000_000 * $pricing[0]) + ($s['tokens_out'] / 1_000_000 * $pricing[1]);
            $totalCost += $cost;

            $io->text(sprintf('  %s: $%.4f (%s in + %s out tokens)', $label, $cost, number_format($s['tokens_in']), number_format($s['tokens_out'])));
        }

        $io->text(sprintf('  <info>Total: $%.4f</info>', $totalCost));
        $io->newLine();
    }

    /**
     * Collect per-model aggregate statistics from the results.
     *
     * @param array<string, array<string, array{score: int, grade: string, summary: string, reasoning: string, tokens_in: int, tokens_out: int, duration: int}|null>> $results
     *
     * @return array<string, array{scores: list<int>, grades: list<string>, tokens_in: int, tokens_out: int, duration: int, errors: int}>
     */
    private function collectModelStats(array $results): array
    {
        /** @var array<string, list<int>> $scores */
        $scores = [];

        /** @var array<string, list<string>> $grades */
        $grades = [];

        /** @var array<string, int> $tokensIn */
        $tokensIn = [];

        /** @var array<string, int> $tokensOut */
        $tokensOut = [];

        /** @var array<string, int> $durations */
        $durations = [];

        /** @var array<string, int> $errors */
        $errors = [];

        foreach (self::DEFAULT_MODELS as $modelId) {
            $scores[$modelId]    = [];
            $grades[$modelId]    = [];
            $tokensIn[$modelId]  = 0;
            $tokensOut[$modelId] = 0;
            $durations[$modelId] = 0;
            $errors[$modelId]    = 0;
        }

        foreach ($results as $modelResults) {
            foreach (self::DEFAULT_MODELS as $modelId) {
                $data = $modelResults[$modelId] ?? null;

                if ($data === null) {
                    ++$errors[$modelId];

                    continue;
                }

                $scores[$modelId][] = $data['score'];
                $grades[$modelId][] = $data['grade'];
                $tokensIn[$modelId] += $data['tokens_in'];
                $tokensOut[$modelId] += $data['tokens_out'];
                $durations[$modelId] += $data['duration'];
            }
        }

        $stats = [];

        foreach (self::DEFAULT_MODELS as $modelId) {
            $stats[$modelId] = [
                'scores'     => $scores[$modelId],
                'grades'     => $grades[$modelId],
                'tokens_in'  => $tokensIn[$modelId],
                'tokens_out' => $tokensOut[$modelId],
                'duration'   => $durations[$modelId],
                'errors'     => $errors[$modelId],
            ];
        }

        return $stats;
    }
}
