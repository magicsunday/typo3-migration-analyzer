<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Llm;

use App\Llm\ClaudeClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for the ClaudeClient.
 */
#[CoversClass(ClaudeClient::class)]
final class ClaudeClientTest extends TestCase
{
    #[Test]
    public function analyzeReturnsStructuredResponse(): void
    {
        $responseBody = json_encode([
            'content' => [
                ['type' => 'text', 'text' => '{"score": 3, "summary": "Test analysis"}'],
            ],
            'usage' => [
                'input_tokens'  => 1500,
                'output_tokens' => 500,
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $httpClient = new MockHttpClient($mockResponse);
        $client     = new ClaudeClient($httpClient, 'test-api-key');

        $response = $client->analyze('System prompt', 'User prompt', 'claude-haiku-4-5-20251001');

        self::assertSame('{"score": 3, "summary": "Test analysis"}', $response->content);
        self::assertSame(1500, $response->inputTokens);
        self::assertSame(500, $response->outputTokens);
        self::assertGreaterThanOrEqual(0, $response->durationMs);
    }

    #[Test]
    public function analyzeSendsCorrectRequestHeaders(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'content' => [['type' => 'text', 'text' => '{}']],
            'usage'   => ['input_tokens' => 100, 'output_tokens' => 50],
        ], JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $httpClient = new MockHttpClient($mockResponse);
        $client     = new ClaudeClient($httpClient, 'sk-ant-test-key');

        $client->analyze('system', 'user', 'claude-sonnet-4-6');

        self::assertSame('POST', $mockResponse->getRequestMethod());
        self::assertStringContainsString('api.anthropic.com', $mockResponse->getRequestUrl());
    }
}
