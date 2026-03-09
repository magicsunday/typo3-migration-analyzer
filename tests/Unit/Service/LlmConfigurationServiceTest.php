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
use App\Dto\LlmModel;
use App\Dto\LlmProvider;
use App\Llm\LlmModelProviderFactory;
use App\Service\LlmConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_find;
use function file_exists;
use function is_dir;
use function json_encode;
use function rmdir;
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
        $service = $this->createService();
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
        $service = $this->createService();
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
        $service = $this->createService();

        self::assertFalse($service->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsTrueWithApiKey(): void
    {
        $service = $this->createService();
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
    public function getAvailableModelsReturnsStaticFallbackWithoutApiKey(): void
    {
        $service = $this->createService();
        $models  = $service->getAvailableModels();

        self::assertNotEmpty($models);

        // Static fallback models all have pricing
        foreach ($models as $model) {
            self::assertNotNull($model->inputCostPerMillion);
        }
    }

    #[Test]
    public function getAvailableModelsReturnsDynamicModelsFromApi(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
                ['id' => 'claude-unknown-model', 'display_name' => 'Claude Unknown', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
        ], JSON_THROW_ON_ERROR));

        $service = new LlmConfigurationService(
            $this->tempDir,
            new LlmModelProviderFactory(new MockHttpClient($mockResponse)),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $models = $service->getAvailableModels(LlmProvider::Claude, 'test-key');

        self::assertCount(2, $models);

        // Known model has pricing
        $sonnet = array_find($models, static fn (LlmModel $m): bool => $m->modelId === 'claude-sonnet-4-6');
        self::assertNotNull($sonnet);
        self::assertSame(3.00, $sonnet->inputCostPerMillion);
        self::assertSame(15.00, $sonnet->outputCostPerMillion);

        // Unknown model has null pricing
        $unknown = array_find($models, static fn (LlmModel $m): bool => $m->modelId === 'claude-unknown-model');
        self::assertNotNull($unknown);
        self::assertNull($unknown->inputCostPerMillion);
    }

    #[Test]
    public function getAvailableModelsFallsBackToStaticListOnApiError(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 500]);

        $service = new LlmConfigurationService(
            $this->tempDir,
            new LlmModelProviderFactory(new MockHttpClient($mockResponse)),
            new ArrayAdapter(),
            new NullLogger(),
        );

        $models = $service->getAvailableModels(LlmProvider::Claude, 'test-key');

        self::assertNotEmpty($models);

        // Static fallback models all have pricing
        foreach ($models as $model) {
            self::assertNotNull($model->inputCostPerMillion);
        }
    }

    #[Test]
    public function getAvailableModelsCachesApiResults(): void
    {
        $callCount    = 0;
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
        ], JSON_THROW_ON_ERROR));

        $httpClient = new MockHttpClient(function () use ($mockResponse, &$callCount): MockResponse {
            ++$callCount;

            return $mockResponse;
        });

        $service = new LlmConfigurationService(
            $this->tempDir,
            new LlmModelProviderFactory($httpClient),
            new ArrayAdapter(),
            new NullLogger(),
        );

        // First call hits API
        $service->getAvailableModels(LlmProvider::Claude, 'test-key');
        // Second call uses cache
        $service->getAvailableModels(LlmProvider::Claude, 'test-key');

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function getPromptVersionChangesWhenPromptChanges(): void
    {
        $service  = $this->createService();
        $version1 = $service->getPromptVersion('Prompt A');
        $version2 = $service->getPromptVersion('Prompt B');

        self::assertNotSame($version1, $version2);
    }

    #[Test]
    public function getPromptVersionIsDeterministic(): void
    {
        $service = $this->createService();

        self::assertSame(
            $service->getPromptVersion('Same prompt'),
            $service->getPromptVersion('Same prompt'),
        );
    }

    #[Test]
    public function getDefaultPromptReturnsNonEmptyString(): void
    {
        $service = $this->createService();

        self::assertNotSame('', $service->getDefaultPrompt());
        self::assertStringContainsString('TYPO3', $service->getDefaultPrompt());
    }

    private function createService(): LlmConfigurationService
    {
        return new LlmConfigurationService(
            $this->tempDir,
            new LlmModelProviderFactory(new MockHttpClient()),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }
}
