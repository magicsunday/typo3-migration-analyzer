<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\LlmConfiguration;
use App\Dto\LlmProvider;
use App\Service\LlmConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Tests for the LlmConfigurationService.
 */
#[CoversClass(LlmConfigurationService::class)]
final class LlmConfigurationServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = tempnam(sys_get_temp_dir(), 'llm_test_');
        unlink($this->tempDir);
    }

    protected function tearDown(): void
    {
        $configFile = $this->tempDir . '/llm_config.yaml';

        if (file_exists($configFile)) {
            unlink($configFile);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    #[Test]
    public function loadReturnsDefaultsWhenNoConfigExists(): void
    {
        $service = new LlmConfigurationService($this->tempDir);
        $config  = $service->load();

        self::assertSame(LlmProvider::Claude, $config->provider);
        self::assertSame('claude-haiku-4-5-20251001', $config->modelId);
        self::assertSame('', $config->apiKey);
        self::assertNotSame('', $config->analysisPrompt);
        self::assertNotSame('', $config->promptVersion);
    }

    #[Test]
    public function saveAndLoadRoundTrip(): void
    {
        $service = new LlmConfigurationService($this->tempDir);
        $config  = $service->load();

        $modified = new LlmConfiguration(
            provider: LlmProvider::OpenAi,
            modelId: 'gpt-4o-mini',
            apiKey: 'sk-test-key',
            analysisPrompt: $config->analysisPrompt,
            promptVersion: $config->promptVersion,
        );

        $service->save($modified);

        $loaded = $service->load();

        self::assertSame(LlmProvider::OpenAi, $loaded->provider);
        self::assertSame('gpt-4o-mini', $loaded->modelId);
        self::assertSame('sk-test-key', $loaded->apiKey);
    }

    #[Test]
    public function isConfiguredReturnsFalseWithoutApiKey(): void
    {
        $service = new LlmConfigurationService($this->tempDir);

        self::assertFalse($service->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsTrueWithApiKey(): void
    {
        $service = new LlmConfigurationService($this->tempDir);
        $config  = $service->load();

        $withKey = new LlmConfiguration(
            provider: $config->provider,
            modelId: $config->modelId,
            apiKey: 'sk-test',
            analysisPrompt: $config->analysisPrompt,
            promptVersion: $config->promptVersion,
        );

        $service->save($withKey);

        self::assertTrue($service->isConfigured());
    }

    #[Test]
    public function getAvailableModelsReturnsNonEmptyList(): void
    {
        $service = new LlmConfigurationService($this->tempDir);
        $models  = $service->getAvailableModels();

        self::assertNotEmpty($models);
        self::assertSame('claude-haiku-4-5-20251001', $models[0]->modelId);
    }

    #[Test]
    public function getPromptVersionChangesWhenPromptChanges(): void
    {
        $service  = new LlmConfigurationService($this->tempDir);
        $version1 = $service->getPromptVersion('Prompt A');
        $version2 = $service->getPromptVersion('Prompt B');

        self::assertNotSame($version1, $version2);
    }

    #[Test]
    public function getPromptVersionIsDeterministic(): void
    {
        $service = new LlmConfigurationService($this->tempDir);

        self::assertSame(
            $service->getPromptVersion('Same prompt'),
            $service->getPromptVersion('Same prompt'),
        );
    }

    #[Test]
    public function getDefaultPromptReturnsNonEmptyString(): void
    {
        $service = new LlmConfigurationService($this->tempDir);

        self::assertNotSame('', $service->getDefaultPrompt());
        self::assertStringContainsString('TYPO3', $service->getDefaultPrompt());
    }
}
