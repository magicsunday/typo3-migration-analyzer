<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Llm;

use App\Dto\LlmProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates an LLM client for the given provider.
 */
final readonly class LlmClientFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Create an LLM client for the given provider and API key.
     */
    public function create(LlmProvider $provider, string $apiKey): LlmClientInterface
    {
        return match ($provider) {
            LlmProvider::Claude => new ClaudeClient($this->httpClient, $apiKey),
            LlmProvider::OpenAi => new OpenAiClient($this->httpClient, $apiKey),
        };
    }
}
