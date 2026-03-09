<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Llm;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function hrtime;
use function json_decode;
use function sprintf;

/**
 * LLM client for the OpenAI Chat Completions API.
 *
 * Uses response_format: json_object to enforce JSON output.
 */
final readonly class OpenAiClient implements LlmClientInterface
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';

    private const int TIMEOUT_SECONDS = 60;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    public function analyze(string $systemPrompt, string $userPrompt, string $modelId): LlmResponse
    {
        $startTime = hrtime(true);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'           => $modelId,
                'max_tokens'      => 2048,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

        try {
            /** @var array{choices: list<array{message: array{content: string}}>, usage: array{prompt_tokens: int, completion_tokens: int}} $data */
            $data = $response->toArray();
        } catch (ClientExceptionInterface $e) {
            $body = $response->getContent(false);

            /** @var array{error?: array{message?: string}}|null $error */
            $error   = json_decode($body, true);
            $message = $error['error']['message'] ?? $body;

            throw new RuntimeException(sprintf('OpenAI API error: %s', $message), $e->getCode(), $e);
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new LlmResponse(
            content: $data['choices'][0]['message']['content'],
            inputTokens: $data['usage']['prompt_tokens'],
            outputTokens: $data['usage']['completion_tokens'],
            durationMs: $durationMs,
        );
    }
}
