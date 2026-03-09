<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\LlmConfiguration;
use App\Dto\LlmModel;
use App\Dto\LlmProvider;
use App\Llm\LlmModelProviderFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

use function file_exists;
use function file_put_contents;
use function hash;
use function is_dir;
use function mkdir;
use function sprintf;
use function str_starts_with;

/**
 * Manages LLM configuration (provider, model, API key, prompt) persisted in YAML.
 *
 * Provides dynamic model listing from provider APIs with local pricing enrichment
 * and static fallback when the API is unreachable.
 */
final readonly class LlmConfigurationService
{
    private const string CONFIG_FILENAME = 'llm_config.yaml';

    private const int MODEL_CACHE_TTL = 3600;

    /**
     * Known model pricing: model ID => [input cost per million, output cost per million].
     *
     * @var array<string, array{float, float}>
     */
    private const array PRICING_MAP = [
        // Claude
        'claude-opus-4-6'           => [5.00, 25.00],
        'claude-opus-4-5'           => [5.00, 25.00],
        'claude-opus-4-1'           => [15.00, 75.00],
        'claude-sonnet-4-6'         => [3.00, 15.00],
        'claude-sonnet-4-5'         => [3.00, 15.00],
        'claude-sonnet-4'           => [3.00, 15.00],
        'claude-haiku-4-5-20251001' => [1.00, 5.00],
        // OpenAI — GPT-5 series
        'gpt-5.4'    => [2.50, 15.00],
        'gpt-5.2'    => [1.75, 14.00],
        'gpt-5.1'    => [1.25, 10.00],
        'gpt-5'      => [1.25, 10.00],
        'gpt-5-mini' => [0.25, 2.00],
        'gpt-5-nano' => [0.05, 0.40],
        // OpenAI — GPT-4 series
        'gpt-4.1'      => [2.00, 8.00],
        'gpt-4.1-mini' => [0.40, 1.60],
        'gpt-4.1-nano' => [0.10, 0.40],
        'gpt-4o'       => [2.50, 10.00],
        'gpt-4o-mini'  => [0.15, 0.60],
        // OpenAI — Reasoning models
        'o1'      => [15.00, 60.00],
        'o3'      => [2.00, 8.00],
        'o3-mini' => [1.10, 4.40],
        'o4-mini' => [1.10, 4.40],
        'o1-mini' => [1.10, 4.40],
    ];

    public function __construct(
        private string $dataDir,
        private LlmModelProviderFactory $modelProviderFactory,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Load the current configuration, falling back to defaults.
     */
    public function load(): LlmConfiguration
    {
        $configPath = $this->getConfigPath();

        if (!file_exists($configPath)) {
            return $this->createDefault();
        }

        /** @var array{provider?: string, model_id?: string, api_key?: string, analysis_prompt?: string} $data */
        $data   = Yaml::parseFile($configPath);
        $prompt = $data['analysis_prompt'] ?? $this->getDefaultPrompt();

        return new LlmConfiguration(
            provider: LlmProvider::tryFrom($data['provider'] ?? '') ?? LlmProvider::Claude,
            modelId: $data['model_id'] ?? 'claude-haiku-4-5-20251001',
            apiKey: $data['api_key'] ?? '',
            analysisPrompt: $prompt,
            promptVersion: $this->getPromptVersion($prompt),
        );
    }

    /**
     * Save the configuration to disk and invalidate the model cache.
     */
    public function save(LlmConfiguration $config): void
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0o755, true)) {
            throw new RuntimeException(sprintf('Failed to create directory: %s', $this->dataDir));
        }

        $data = [
            'provider'        => $config->provider->value,
            'model_id'        => $config->modelId,
            'api_key'         => $config->apiKey,
            'analysis_prompt' => $config->analysisPrompt,
        ];

        file_put_contents($this->getConfigPath(), Yaml::dump($data, 4));

        // No explicit cache invalidation needed — cache keys include API key hash,
        // so a new key automatically triggers a fresh API call. Old entries expire via TTL.
    }

    /**
     * Whether an API key has been configured.
     */
    public function isConfigured(): bool
    {
        $config = $this->load();

        return $config->apiKey !== '';
    }

    /**
     * Returns available models for the given provider.
     *
     * Fetches models from the provider API (cached for 1 hour),
     * enriches them with known pricing, and falls back to a static
     * list from the pricing map if the API is unreachable.
     *
     * @return list<LlmModel>
     */
    public function getAvailableModels(?LlmProvider $provider = null, ?string $apiKey = null): array
    {
        $config = $this->load();
        $provider ??= $config->provider;
        $apiKey ??= $config->apiKey;

        if ($apiKey === '') {
            return $this->getStaticModels($provider);
        }

        $cacheKey = 'llm_models_' . $provider->value . '_' . hash('xxh64', $apiKey);

        try {
            /** @var list<LlmModel> */
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($provider, $apiKey): array {
                $item->expiresAfter(self::MODEL_CACHE_TTL);

                $modelProvider = $this->modelProviderFactory->create($provider);
                $models        = $modelProvider->listModels($apiKey);

                return $this->enrichWithPricing($models);
            });
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch models from API, using static fallback.', [
                'provider' => $provider->value,
                'error'    => $e->getMessage(),
            ]);

            return $this->getStaticModels($provider);
        }
    }

    /**
     * Generate a version hash for the given prompt text.
     */
    public function getPromptVersion(string $prompt): string
    {
        return hash('xxh64', $prompt);
    }

    /**
     * Returns the default analysis prompt.
     */
    public function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
            You are a TYPO3 migration expert specialized in analyzing TYPO3 Core changelog RST files from Documentation/Changelog.

            Your task is to analyze a single TYPO3 RST changelog document describing a deprecation or breaking change and return exactly one JSON object.

            Output rules:
            - Respond with exactly one valid JSON object.
            - Do not use markdown.
            - Do not use code fences.
            - Do not add explanations before or after the JSON.
            - All keys must always be present.
            - If information is missing, use null, false, an empty array, or an empty string as appropriate.
            - Base your analysis only on the provided RST content.
            - Do not invent replacements if the document does not clearly provide one.
            - Prefer explicit migration instructions from the RST over assumptions.
            - Extract concrete old→new mappings only when supported by the document text or code examples.

            Document interpretation rules:
            TYPO3 changelog RST files usually contain sections such as Description, Impact, Affected Installations, Migration.
            Use them as follows:
            - "Description": identify what changed
            - "Impact": identify technical consequences and severity
            - "Affected Installations": determine who is affected and which project areas are involved
            - "Migration": extract concrete migration steps and replacements
            - Also inspect RST code blocks (php, typoscript, yaml, xml, html, sql, shell, javascript) for before/after examples

            Scope rules:
            - Focus on migration impact for TYPO3 projects and extensions.
            - Consider PHP API usage, configuration, DI, TCA, TypoScript, TSconfig, Fluid, YAML, SQL, hooks, PSR-14 events, Extbase, Symfony service configuration, backend/frontend integration.
            - Detect whether the change is automatable via Rector, search-and-replace, or requires manual refactoring.
            - If this is primarily a behavioral change without a strict API replacement, reflect that in score, migration steps, and Rector assessment.

            Return exactly this JSON structure:

            {
              "score": 1,
              "automation_grade": "full|partial|manual",
              "summary": "string",
              "reasoning": "string",
              "migration_steps": ["string"],
              "affected_areas": ["PHP"],
              "affected_components": ["string"],
              "code_mappings": [{"old": "string", "new": "string|null", "type": "string"}],
              "rector_assessment": {"feasible": true, "rule_type": "string|null", "notes": "string"}
            }

            Field requirements:

            "score" (integer 1-5): migration complexity
            - 1 = trivial rename/move, fully automatable
            - 2 = simple replacement with clear instructions
            - 3 = moderate code changes or signature/config updates requiring review
            - 4 = complex refactoring, pattern migration, hook→event, service architecture changes, non-trivial TCA restructuring
            - 5 = architectural/manual redesign, removal without practical replacement, semantic redesign required
            Scoring guidance:
            - Simple class rename or method rename with direct replacement: 1
            - Direct API replacement with limited edits: 2
            - Signature change, changed return type, changed config structure, or review needed: 3
            - Hook to PSR-14 event, DI refactor, middleware pattern, TCA restructuring: 4
            - No replacement or redesign of integration concept required: 5
            - Do not increase the score merely because non-PHP files are involved
            - Increase the score only if those files require meaningful manual migration effort

            "automation_grade" (string): "full", "partial", or "manual"
            - "full" = can realistically be migrated completely by Rector or deterministic replacement
            - "partial" = some parts automatable, but manual review/refactoring still required
            - "manual" = mostly manual migration required

            "summary" (string): One concise sentence describing what changed and why it matters.

            "reasoning" (string): Brief justification for score and automation_grade in 1-3 sentences. Mention the deciding factor: rename, signature change, hook→event, behavior change, no replacement, config restructure, etc.

            "migration_steps" (array of strings): Concrete actionable migration steps. Prefer imperative wording. Mention fully qualified class names where applicable. Include manual review steps when necessary.

            "affected_areas" (array of strings): Use only values from: "PHP", "Fluid", "TCA", "TypoScript", "TSconfig", "JavaScript", "YAML", "Flexform", "ext_localconf.php", "ext_tables.php", "Services.yaml", "Configuration/Icons.php", "SQL". Include only areas clearly affected by the document.

            "affected_components" (array of strings): Short TYPO3-oriented technical categories affected, for example: "Core API", "Extbase", "Backend", "Frontend", "Dependency Injection", "PSR-14 Events", "Hooks", "Caching", "Authentication", "Routing", "TCA", "FormEngine", "Install Tool", "CLI", "TypoScript", "Fluid", "Database", "Workspace", "Scheduler", "Link Handling". Use only relevant items supported by the document.

            "code_mappings" (array of objects): Extract explicit old→new mappings where possible. Each object:
            - "old": old class, method, hook, option, constant, service tag, config path, TypoScript property, TCA option, etc.
            - "new": replacement, or null if removed without replacement
            - "type": one of "class_rename", "method_rename", "constant_rename", "argument_change", "method_removal", "class_removal", "hook_to_event", "hook_removal", "event_introduced", "service_tag_change", "dependency_injection_change", "extbase_api_change", "typoscript_change", "typoscript_property_rename", "tca_change", "tca_option_removed", "configuration_path_change", "behavior_change"
            Mapping rules:
            - Use fully qualified class names where available.
            - For hooks, include the hook identifier or registration location as precisely as possible.
            - If the document describes a removal without replacement, set "new" to null.
            - If only changed behavior and no direct replacement exists, use "behavior_change".
            - Do not fabricate mappings.

            "rector_assessment" (object):
            - "feasible" (boolean): can a Rector rule reasonably automate the migration?
            - "rule_type" (string|null): best fitting Rector rule or strategy, e.g. "RenameClassRector", "RenameMethodRector", "MethodCallToStaticCallRector", "ArgumentRemoverRector", "ArgumentAdderRector", "RemoveMethodCallRector", "ConfiguredCodeSampleRector", "CustomRector", or null
            - "notes" (string): short explanation of feasibility and limitations
            Rules:
            - Set feasible=true for deterministic renames and clear method/class replacements
            - Set feasible=true with "CustomRector" for structured but project-wide transformations
            - Set feasible=false when semantic understanding, manual TCA redesign, or business logic refactoring is required
            - Hook→event migrations are usually "partial" and often require "CustomRector" or feasible=false
            - Pure behavior changes without syntax-level signals are usually not feasible

            TYPO3-specific heuristics:
            - GeneralUtility::makeInstance() to constructor injection is usually partial, not full
            - Hook to PSR-14 event migration is rarely full automation
            - TCA restructuring usually requires manual review
            - TypoScript property renames may be fully automatable if exact old/new properties are clear
            - Removed APIs without replacement should usually score 4 or 5
            - If the migration section contains explicit before/after examples, strongly prefer those mappings
            - If the RST only announces deprecation but existing code still works for now, assess migration complexity based on the future required migration
            - If the document explicitly states the change affects practically no installations or has only one internal implementation, score 1 and consider full automation
            - Interface signature additions (new methods, added type hints) on interfaces with a single known implementation are trivially automatable via Rector

            Quality requirements:
            - Be precise and conservative
            - Avoid over-scoring trivial changes
            - Avoid under-scoring pattern migrations
            - Score and automation_grade must be consistent: score 1-2 with "manual" is only valid for configuration-only changes where no code transformation exists
            PROMPT;
    }

    /**
     * Enrich models with known pricing from the local pricing map.
     *
     * @param list<LlmModel> $models
     *
     * @return list<LlmModel>
     */
    private function enrichWithPricing(array $models): array
    {
        $enriched = [];

        foreach ($models as $model) {
            $pricing = self::PRICING_MAP[$model->modelId] ?? null;

            $enriched[] = new LlmModel(
                provider: $model->provider,
                modelId: $model->modelId,
                label: $model->label,
                inputCostPerMillion: $model->inputCostPerMillion ?? $pricing[0] ?? null,
                outputCostPerMillion: $model->outputCostPerMillion ?? $pricing[1] ?? null,
            );
        }

        return $enriched;
    }

    /**
     * Build a static model list from the pricing map as fallback.
     *
     * @return list<LlmModel>
     */
    private function getStaticModels(LlmProvider $provider): array
    {
        $models = [];

        foreach (self::PRICING_MAP as $modelId => $pricing) {
            if ($this->guessProvider($modelId) !== $provider) {
                continue;
            }

            $models[] = new LlmModel(
                provider: $provider,
                modelId: $modelId,
                label: $modelId,
                inputCostPerMillion: $pricing[0],
                outputCostPerMillion: $pricing[1],
            );
        }

        return $models;
    }

    /**
     * Guess the provider from a model ID based on naming conventions.
     */
    private function guessProvider(string $modelId): LlmProvider
    {
        if (str_starts_with($modelId, 'claude')) {
            return LlmProvider::Claude;
        }

        return LlmProvider::OpenAi;
    }

    /**
     * Create a default configuration with no API key.
     */
    private function createDefault(): LlmConfiguration
    {
        $prompt = $this->getDefaultPrompt();

        return new LlmConfiguration(
            provider: LlmProvider::Claude,
            modelId: 'claude-haiku-4-5-20251001',
            apiKey: '',
            analysisPrompt: $prompt,
            promptVersion: $this->getPromptVersion($prompt),
        );
    }

    private function getConfigPath(): string
    {
        return $this->dataDir . '/' . self::CONFIG_FILENAME;
    }
}
