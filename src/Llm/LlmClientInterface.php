<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Llm;

/**
 * Abstraction for LLM API calls.
 */
interface LlmClientInterface
{
    /**
     * Send a prompt to the LLM and return the structured response.
     */
    public function analyze(string $systemPrompt, string $userPrompt, string $modelId): LlmResponse;
}
