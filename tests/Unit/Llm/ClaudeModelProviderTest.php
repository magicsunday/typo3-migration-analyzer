<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Dto\LlmProvider;
use App\Llm\ClaudeModelProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function json_encode;

/**
 * Tests for the ClaudeModelProvider.
 */
#[CoversClass(ClaudeModelProvider::class)]
final class ClaudeModelProviderTest extends TestCase
{
    #[Test]
    public function listModelsReturnsModelsFromApi(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
                ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5', 'type' => 'model', 'created_at' => '2025-10-01T00:00:00Z'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new ClaudeModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertCount(2, $models);
        // Sorted by label: Haiku before Sonnet
        self::assertSame('claude-haiku-4-5-20251001', $models[0]->modelId);
        self::assertSame('Claude Haiku 4.5', $models[0]->label);
        self::assertSame(LlmProvider::Claude, $models[0]->provider);
        self::assertNull($models[0]->inputCostPerMillion);
        self::assertNull($models[0]->outputCostPerMillion);
    }

    #[Test]
    public function listModelsReturnsEmptyListForEmptyResponse(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [],
        ], JSON_THROW_ON_ERROR));

        $provider = new ClaudeModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertSame([], $models);
    }
}
