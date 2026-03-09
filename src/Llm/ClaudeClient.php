<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Llm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

use function hrtime;
use function ltrim;

/**
 * LLM client for the Anthropic Messages API.
 *
 * Uses an assistant prefill ("{") to force JSON output and strips any
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
                'max_tokens' => 2048,
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                    // Assistant prefill to force JSON output
                    ['role' => 'assistant', 'content' => '{'],
                ],
            ],
        ]);

        /** @var array{content: list<array{text: string}>, usage: array{input_tokens: int, output_tokens: int}} $data */
        $data       = $response->toArray();
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // Prepend the "{" prefill that Claude continues from
        $content = '{' . ltrim($data['content'][0]['text']);
        $content = $this->stripMarkdownFences($content);

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
