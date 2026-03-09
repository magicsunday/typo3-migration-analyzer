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

/**
 * Abstraction for fetching available models from an LLM provider API.
 */
interface LlmModelProviderInterface
{
    /**
     * Fetch available models from the provider API.
     *
     * @return list<LlmModel>
     */
    public function listModels(string $apiKey): array;
}
