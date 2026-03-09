<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Llm;

use App\Dto\LlmModel;
use App\Dto\LlmProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_any;
use function str_starts_with;
use function usort;

/**
 * Fetches available chat models from the OpenAI Models API.
 *
 * Filters out embedding, audio, image, and other non-chat models
 * by checking the model ID against known chat model prefixes.
 */
final readonly class OpenAiModelProvider implements LlmModelProviderInterface
{
    private const string API_URL = 'https://api.openai.com/v1/models';

    private const int TIMEOUT_SECONDS = 10;

    /**
     * Model ID prefixes that indicate chat-capable models.
     */
    private const array CHAT_MODEL_PREFIXES = [
        'gpt-',
        'o1-',
        'o3-',
        'o4-',
        'chatgpt-',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Fetch available chat models from the OpenAI API.
     *
     * @return list<LlmModel>
     */
    public function listModels(string $apiKey): array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
        ]);

        /** @var array{data: list<array{id: string, owned_by: string}>} $data */
        $data = $response->toArray();

        $models = [];

        foreach ($data['data'] as $model) {
            if (!$this->isChatModel($model['id'])) {
                continue;
            }

            $models[] = new LlmModel(
                provider: LlmProvider::OpenAi,
                modelId: $model['id'],
                label: $model['id'],
                inputCostPerMillion: null,
                outputCostPerMillion: null,
            );
        }

        usort($models, static fn (LlmModel $a, LlmModel $b): int => $a->modelId <=> $b->modelId);

        return $models;
    }

    /**
     * Check whether a model ID belongs to a chat-capable model.
     */
    private function isChatModel(string $modelId): bool
    {
        return array_any(
            self::CHAT_MODEL_PREFIXES,
            static fn (string $prefix): bool => str_starts_with($modelId, $prefix),
        );
    }
}
