<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\OpenAiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for the OpenAiClient.
 */
#[CoversClass(OpenAiClient::class)]
final class OpenAiClientTest extends TestCase
{
    #[Test]
    public function analyzeReturnsStructuredResponse(): void
    {
        $responseBody = json_encode([
            'choices' => [
                ['message' => ['content' => '{"score": 2, "summary": "Simple rename"}']],
            ],
            'usage' => [
                'prompt_tokens'     => 1200,
                'completion_tokens' => 400,
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $httpClient = new MockHttpClient($mockResponse);
        $client     = new OpenAiClient($httpClient, 'sk-test-key');

        $response = $client->analyze('System prompt', 'User prompt', 'gpt-4o-mini');

        self::assertSame('{"score": 2, "summary": "Simple rename"}', $response->content);
        self::assertSame(1200, $response->inputTokens);
        self::assertSame(400, $response->outputTokens);
        self::assertGreaterThanOrEqual(0, $response->durationMs);
    }

    #[Test]
    public function analyzeSendsCorrectRequestHeaders(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'choices' => [['message' => ['content' => '{}']]],
            'usage'   => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ], JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $httpClient = new MockHttpClient($mockResponse);
        $client     = new OpenAiClient($httpClient, 'sk-openai-test');

        $client->analyze('system', 'user', 'gpt-4o');

        self::assertSame('POST', $mockResponse->getRequestMethod());
        self::assertStringContainsString('api.openai.com', $mockResponse->getRequestUrl());
    }
}
