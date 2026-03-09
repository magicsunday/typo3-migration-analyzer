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
 * LLM client for the Anthropic Messages API.
 *
 * Appends a JSON-only instruction to the system prompt and strips any
 * markdown code fences from the response before returning.
 */
final readonly class ClaudeClient implements LlmClientInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';

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
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'json' => [
                'model'      => $modelId,
                'max_tokens' => 8192,
                'system'     => $systemPrompt . "\n\nIMPORTANT: Respond with raw JSON only. No markdown, no code fences, no explanation.",
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

        try {
            /** @var array{content: list<array{text: string}>, stop_reason?: string, usage: array{input_tokens: int, output_tokens: int}} $data */
            $data = $response->toArray();
        } catch (ClientExceptionInterface $e) {
            $body = $response->getContent(false);

            /** @var array{error?: array{message?: string}}|null $error */
            $error   = json_decode($body, true);
            $message = $error['error']['message'] ?? $body;

            throw new RuntimeException(sprintf('Claude API error: %s', $message), $e->getCode(), $e);
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        if (($data['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException('Claude API response was truncated (max_tokens reached). The document may be too large for analysis.');
        }

        $content = $this->stripMarkdownFences($data['content'][0]['text']);

        return new LlmResponse(
            content: $content,
            inputTokens: $data['usage']['input_tokens'],
            outputTokens: $data['usage']['output_tokens'],
            durationMs: $durationMs,
        );
    }

    /**
     * Strip markdown code fences (```json ... ```) from LLM output.
     */
    private function stripMarkdownFences(string $content): string
    {
        // Remove leading ```json and trailing ```
        $content = preg_replace('/^\s*```(?:json)?\s*/i', '', $content) ?? $content;

        return preg_replace('/\s*```\s*$/', '', $content) ?? $content;
    }
}
