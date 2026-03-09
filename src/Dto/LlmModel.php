<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents an LLM model with its provider, identifier, and cost information.
 */
final readonly class LlmModel
{
    /**
     * @param float $inputCostPerMillion  Cost per million input tokens in USD
     * @param float $outputCostPerMillion Cost per million output tokens in USD
     */
    public function __construct(
        public LlmProvider $provider,
        public string $modelId,
        public string $label,
        public float $inputCostPerMillion,
        public float $outputCostPerMillion,
    ) {
    }

    /**
     * Returns the estimated cost for the given token counts.
     */
    public function estimateCost(int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens / 1_000_000) * $this->inputCostPerMillion
            + ($outputTokens / 1_000_000) * $this->outputCostPerMillion;
    }
}
