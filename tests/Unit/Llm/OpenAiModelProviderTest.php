<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Dto\LlmModel;
use App\Dto\LlmProvider;
use App\Llm\OpenAiModelProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_map;
use function json_encode;

/**
 * Tests for the OpenAiModelProvider.
 */
#[CoversClass(OpenAiModelProvider::class)]
final class OpenAiModelProviderTest extends TestCase
{
    #[Test]
    public function listModelsFiltersNonChatModels(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'gpt-4o-mini', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'text-embedding-3-large', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'whisper-1', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'dall-e-3', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'o1-preview', 'object' => 'model', 'owned_by' => 'openai'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new OpenAiModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertCount(3, $models);

        $ids = array_map(static fn (LlmModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-4o', $ids);
        self::assertContains('gpt-4o-mini', $ids);
        self::assertContains('o1-preview', $ids);
        self::assertNotContains('text-embedding-3-large', $ids);
        self::assertNotContains('whisper-1', $ids);
        self::assertNotContains('dall-e-3', $ids);
    }

    #[Test]
    public function listModelsUsesModelIdAsLabel(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'owned_by' => 'openai'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new OpenAiModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertSame('gpt-4o', $models[0]->label);
        self::assertSame(LlmProvider::OpenAi, $models[0]->provider);
        self::assertNull($models[0]->inputCostPerMillion);
    }

    #[Test]
    public function listModelsReturnsEmptyListWhenNoChatModels(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'text-embedding-3-large', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'whisper-1', 'object' => 'model', 'owned_by' => 'openai'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new OpenAiModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertSame([], $models);
    }

    #[Test]
    public function listModelsSortsById(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'gpt-3.5-turbo', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'gpt-4o-mini', 'object' => 'model', 'owned_by' => 'openai'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new OpenAiModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertSame('gpt-3.5-turbo', $models[0]->modelId);
        self::assertSame('gpt-4o', $models[1]->modelId);
        self::assertSame('gpt-4o-mini', $models[2]->modelId);
    }
}
