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
 * Creates a model provider for the given LLM provider.
 */
final readonly class LlmModelProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Create a model provider for the given LLM provider.
     */
    public function create(LlmProvider $provider): LlmModelProviderInterface
    {
        return match ($provider) {
            LlmProvider::Claude => new ClaudeModelProvider($this->httpClient),
            LlmProvider::OpenAi => new OpenAiModelProvider($this->httpClient),
        };
    }
}
