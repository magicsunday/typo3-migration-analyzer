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

use function usort;

/**
 * Fetches available models from the Anthropic Models API.
 */
final readonly class ClaudeModelProvider implements LlmModelProviderInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/models';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Fetch available Claude models from the Anthropic API.
     *
     * @return list<LlmModel>
     */
    public function listModels(string $apiKey): array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'query' => [
                'limit' => 1000,
            ],
        ]);

        /** @var array{data: list<array{id: string, display_name: string}>} $data */
        $data = $response->toArray();

        $models = [];

        foreach ($data['data'] as $model) {
            $models[] = new LlmModel(
                provider: LlmProvider::Claude,
                modelId: $model['id'],
                label: $model['display_name'],
                inputCostPerMillion: null,
                outputCostPerMillion: null,
            );
        }

        usort($models, static fn (LlmModel $a, LlmModel $b): int => $a->label <=> $b->label);

        return $models;
    }
}
