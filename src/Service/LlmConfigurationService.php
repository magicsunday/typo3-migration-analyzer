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
use Symfony\Component\Yaml\Yaml;

use function file_exists;
use function file_put_contents;
use function hash;
use function is_dir;
use function mkdir;

/**
 * Manages LLM configuration (provider, model, API key, prompt) persisted in YAML.
 */
final readonly class LlmConfigurationService
{
    private const string CONFIG_FILENAME = 'llm_config.yaml';

    public function __construct(
        private string $dataDir,
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
     * Save the configuration to disk.
     */
    public function save(LlmConfiguration $config): void
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0o755, true);
        }

        $data = [
            'provider'        => $config->provider->value,
            'model_id'        => $config->modelId,
            'api_key'         => $config->apiKey,
            'analysis_prompt' => $config->analysisPrompt,
        ];

        file_put_contents($this->getConfigPath(), Yaml::dump($data, 4));
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
     * Returns all available models with cost information.
     *
     * @return list<LlmModel>
     */
    public function getAvailableModels(): array
    {
        return [
            new LlmModel(LlmProvider::Claude, 'claude-haiku-4-5-20251001', 'Claude Haiku 4.5', 0.80, 4.00),
            new LlmModel(LlmProvider::Claude, 'claude-sonnet-4-6', 'Claude Sonnet 4.6', 3.00, 15.00),
            new LlmModel(LlmProvider::Claude, 'claude-opus-4-6', 'Claude Opus 4.6', 15.00, 75.00),
            new LlmModel(LlmProvider::OpenAi, 'gpt-4o-mini', 'GPT-4o Mini', 0.15, 0.60),
            new LlmModel(LlmProvider::OpenAi, 'gpt-4o', 'GPT-4o', 2.50, 10.00),
        ];
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
