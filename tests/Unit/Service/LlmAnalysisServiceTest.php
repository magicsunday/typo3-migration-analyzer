<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\AutomationGrade;
use App\Dto\DocumentType;
use App\Dto\LlmAnalysisResult;
use App\Dto\LlmConfiguration;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Llm\LlmClientFactory;
use App\Llm\LlmModelProviderFactory;
use App\Repository\LlmResultRepository;
use App\Service\LlmAnalysisService;
use App\Service\LlmConfigurationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Tests for the LlmAnalysisService.
 */
#[CoversClass(LlmAnalysisService::class)]
final class LlmAnalysisServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = tempnam(sys_get_temp_dir(), 'llm_svc_');
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
    public function analyzeReturnsNullWhenNotConfigured(): void
    {
        $configService = new LlmConfigurationService($this->tempDir, new LlmModelProviderFactory(new MockHttpClient()), new ArrayAdapter(), new NullLogger());
        $factory       = new LlmClientFactory(new MockHttpClient());
        $repository    = new LlmResultRepository(':memory:');

        $service = new LlmAnalysisService($factory, $repository, $configService);

        // No API key configured → returns null
        self::assertNull($service->analyze($this->createDocument()));
    }

    #[Test]
    public function analyzeReturnsCachedResultWhenAvailable(): void
    {
        $configService = $this->createConfiguredService();
        $config        = $configService->load();
        $factory       = new LlmClientFactory(new MockHttpClient());
        $repository    = new LlmResultRepository(':memory:');

        // Pre-populate cache
        $cached = new LlmAnalysisResult(
            filename: 'Deprecation-12345-Test.rst',
            modelId: $config->modelId,
            promptVersion: $config->promptVersion,
            score: 2,
            automationGrade: AutomationGrade::Full,
            summary: 'Cached result',
            migrationSteps: ['Step 1'],
            affectedAreas: ['PHP'],
            codeMappings: [],
            rectorAssessment: null,
            tokensInput: 100,
            tokensOutput: 50,
            durationMs: 500,
            createdAt: '2026-03-09 12:00:00',
        );
        $repository->save($cached);

        $service = new LlmAnalysisService($factory, $repository, $configService);
        $result  = $service->analyze($this->createDocument());

        self::assertNotNull($result);
        self::assertSame('Cached result', $result->summary);
    }

    #[Test]
    public function analyzeCallsLlmWhenNoCachedResult(): void
    {
        $configService = $this->createConfiguredService();

        $innerJson = json_encode([
            'score'            => 3,
            'automation_grade' => 'partial',
            'summary'          => 'Method signature changed',
            'migration_steps'  => ['Update call sites'],
            'affected_areas'   => ['PHP', 'TCA'],
        ], JSON_THROW_ON_ERROR);

        $apiResponse = new MockResponse(json_encode([
            'content' => [
                ['type' => 'text', 'text' => $innerJson],
            ],
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 500],
        ], JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $factory    = new LlmClientFactory(new MockHttpClient($apiResponse));
        $repository = new LlmResultRepository(':memory:');

        $service = new LlmAnalysisService($factory, $repository, $configService);
        $result  = $service->analyze($this->createDocument());

        self::assertNotNull($result);
        self::assertSame(3, $result->score);
        self::assertSame(AutomationGrade::Partial, $result->automationGrade);
        self::assertSame('Method signature changed', $result->summary);
        self::assertSame(['Update call sites'], $result->migrationSteps);
        self::assertSame(['PHP', 'TCA'], $result->affectedAreas);
        self::assertSame(1500, $result->tokensInput);
        self::assertSame(500, $result->tokensOutput);
    }

    #[Test]
    public function analyzeWithForceBypassesCache(): void
    {
        $configService = $this->createConfiguredService();
        $config        = $configService->load();
        $repository    = new LlmResultRepository(':memory:');

        // Pre-populate cache
        $cached = new LlmAnalysisResult(
            filename: 'Deprecation-12345-Test.rst',
            modelId: $config->modelId,
            promptVersion: $config->promptVersion,
            score: 2,
            automationGrade: AutomationGrade::Full,
            summary: 'Old cached result',
            migrationSteps: [],
            affectedAreas: [],
            codeMappings: [],
            rectorAssessment: null,
            tokensInput: 100,
            tokensOutput: 50,
            durationMs: 500,
            createdAt: '2026-03-09 12:00:00',
        );
        $repository->save($cached);

        $innerJson = json_encode([
            'score'            => 4,
            'automation_grade' => 'manual',
            'summary'          => 'Fresh analysis',
            'migration_steps'  => [],
            'affected_areas'   => [],
        ], JSON_THROW_ON_ERROR);
        $apiResponse = new MockResponse(json_encode([
            'content' => [
                ['type' => 'text', 'text' => $innerJson],
            ],
            'usage' => ['input_tokens' => 1500, 'output_tokens' => 500],
        ], JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $factory = new LlmClientFactory(new MockHttpClient($apiResponse));
        $service = new LlmAnalysisService($factory, $repository, $configService);
        $result  = $service->analyze($this->createDocument(), forceReanalyze: true);

        self::assertNotNull($result);
        self::assertSame('Fresh analysis', $result->summary);
    }

    #[Test]
    public function getCachedResultReturnsNullWhenNotCached(): void
    {
        $configService = new LlmConfigurationService($this->tempDir, new LlmModelProviderFactory(new MockHttpClient()), new ArrayAdapter(), new NullLogger());
        $factory       = new LlmClientFactory(new MockHttpClient());
        $repository    = new LlmResultRepository(':memory:');

        $service = new LlmAnalysisService($factory, $repository, $configService);

        self::assertNull($service->getCachedResult('nonexistent.rst'));
    }

    #[Test]
    public function getProgressReturnsCorrectValues(): void
    {
        $configService = new LlmConfigurationService($this->tempDir, new LlmModelProviderFactory(new MockHttpClient()), new ArrayAdapter(), new NullLogger());
        $factory       = new LlmClientFactory(new MockHttpClient());
        $repository    = new LlmResultRepository(':memory:');

        $service  = new LlmAnalysisService($factory, $repository, $configService);
        $progress = $service->getProgress(100);

        self::assertSame(0, $progress['analyzed']);
        self::assertSame(100, $progress['total']);
        self::assertSame(0.0, $progress['percent']);
    }

    #[Test]
    public function getProgressHandlesZeroTotal(): void
    {
        $configService = new LlmConfigurationService($this->tempDir, new LlmModelProviderFactory(new MockHttpClient()), new ArrayAdapter(), new NullLogger());
        $factory       = new LlmClientFactory(new MockHttpClient());
        $repository    = new LlmResultRepository(':memory:');

        $service  = new LlmAnalysisService($factory, $repository, $configService);
        $progress = $service->getProgress(0);

        self::assertSame(0.0, $progress['percent']);
    }

    private function createDocument(): RstDocument
    {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 12345,
            title: 'Test deprecation',
            version: '13.0',
            description: 'Some feature has been deprecated.',
            impact: 'Using the old API triggers a warning.',
            migration: 'Use the new API instead.',
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-12345-Test.rst',
        );
    }

    /**
     * Creates a configured service with an API key set.
     */
    private function createConfiguredService(): LlmConfigurationService
    {
        $configService = new LlmConfigurationService($this->tempDir, new LlmModelProviderFactory(new MockHttpClient()), new ArrayAdapter(), new NullLogger());
        $config        = $configService->load();

        $configService->save(new LlmConfiguration(
            provider: $config->provider,
            modelId: $config->modelId,
            apiKey: 'test-api-key',
            analysisPrompt: $config->analysisPrompt,
            promptVersion: $config->promptVersion,
        ));

        return $configService;
    }
}
