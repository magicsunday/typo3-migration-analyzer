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

/**
 * LLM client for the Anthropic Messages API.
 */
final readonly class ClaudeClient implements LlmClientInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    public function analyze(string $systemPrompt, string $userPrompt, string $modelId): LlmResponse
    {
        $startTime = hrtime(true);

        $response = $this->httpClient->request('POST', self::API_URL, [
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
                ],
            ],
        ]);

        /** @var array{content: list<array{text: string}>, usage: array{input_tokens: int, output_tokens: int}} $data */
        $data       = $response->toArray();
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new LlmResponse(
            content: $data['content'][0]['text'],
            inputTokens: $data['usage']['input_tokens'],
            outputTokens: $data['usage']['output_tokens'],
            durationMs: $durationMs,
        );
    }
}
