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
        'claude-haiku-4-5-20251001' => [0.80, 4.00],
        'claude-sonnet-4-6'         => [3.00, 15.00],
        'claude-opus-4-6'           => [15.00, 75.00],
        'gpt-4o-mini'               => [0.15, 0.60],
        'gpt-4o'                    => [2.50, 10.00],
        'o1-preview'                => [15.00, 60.00],
        'o1-mini'                   => [3.00, 12.00],
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

        // Invalidate model cache so a provider switch fetches fresh models
        $this->cache->delete('llm_models_claude');
        $this->cache->delete('llm_models_openai');
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

        $cacheKey = 'llm_models_' . $provider->value;

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
            You are a TYPO3 migration expert. Analyze the following RST deprecation/breaking
            change document and provide a structured assessment.

            Respond in JSON format with these fields:
            - score (1-5): Migration complexity
              1 = trivial rename/move (fully automatable via Rector)
              2 = simple replacement with clear instructions
              3 = moderate changes requiring code review
              4 = complex refactoring (hook→event, TCA restructure)
              5 = architectural change requiring manual redesign
            - automation_grade: "full", "partial", or "manual"
            - summary: One-sentence description of what changed and why
            - migration_steps: Array of concrete, actionable steps to migrate
            - affected_areas: Array of affected areas (e.g. "PHP", "Fluid", "TCA",
              "TypoScript", "JavaScript", "YAML", "Flexform", "ext_localconf",
              "ext_tables", "Services.yaml")

            Consider ALL aspects:
            - Is there a 1:1 replacement? → score 1-2
            - Does the signature change? → score 3
            - Is it a pattern change (hook→event, middleware)? → score 4
            - Is there no replacement at all? → score 5
            - Are non-PHP files affected (Fluid templates, TCA, TypoScript)? → increase score
            - Can Rector handle this automatically? → automation_grade "full"
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
                inputCostPerMillion: $pricing[0] ?? null,
                outputCostPerMillion: $pricing[1] ?? null,
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
