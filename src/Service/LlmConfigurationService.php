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
            You are a TYPO3 migration expert. Analyze the following RST deprecation/breaking change document and provide a structured assessment.

            Respond with a single JSON object (no markdown, no code fences). Required fields:

            "score" (integer 1-5): Migration complexity.
              1 = trivial rename/move (fully automatable via Rector)
              2 = simple replacement with clear instructions
              3 = moderate changes requiring code review
              4 = complex refactoring (hook→event, TCA restructure)
              5 = architectural change requiring manual redesign

            "automation_grade" (string): One of "full", "partial", or "manual".
              "full" = Rector or search-and-replace can handle it completely
              "partial" = Some parts automatable, some require manual review
              "manual" = Requires manual code changes and review

            "summary" (string): One concise sentence describing what changed and why.

            "migration_steps" (array of strings): Concrete, actionable steps to migrate. Each step must be a plain string. Use fully qualified class names where applicable.

            "affected_areas" (array of strings): Which parts of a TYPO3 project are affected. Use values from: "PHP", "Fluid", "TCA", "TypoScript", "TSconfig", "JavaScript", "YAML", "Flexform", "ext_localconf.php", "ext_tables.php", "Services.yaml", "Configuration/Icons.php", "SQL".

            "code_mappings" (array of objects): Structured old→new code mappings extracted from the document. Each object has:
              "old" (string): The old class, method, constant, hook, or configuration path (fully qualified).
              "new" (string or null): The replacement (fully qualified), or null if removed without replacement.
              "type" (string): One of "class_rename", "method_rename", "constant_rename", "argument_change", "method_removal", "class_removal", "hook_to_event", "typoscript_change", "tca_change", "behavior_change".

            "rector_assessment" (object): Assessment of Rector automation feasibility.
              "feasible" (boolean): Whether a Rector rule can handle this migration automatically.
              "rule_type" (string or null): Suggested Rector rule type (e.g. "RenameClassRector", "RenameMethodRector", "RemoveMethodCallRector"), or null if not feasible.
              "notes" (string): Brief explanation of automation limitations or edge cases.

            Scoring guidelines:
            - 1:1 class/method rename with no signature change → score 1, automation_grade "full"
            - Simple replacement with clear before/after → score 2, automation_grade "full" or "partial"
            - Signature change or conditional logic needed → score 3, automation_grade "partial"
            - Pattern change (hook→PSR-14 event, middleware) → score 4, automation_grade "partial" or "manual"
            - No replacement, architectural redesign needed → score 5, automation_grade "manual"
            - Non-PHP files affected (Fluid, TCA, TypoScript) → increase score by 1
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
