<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use function abs;

/**
 * Represents the complexity assessment of a migration document.
 *
 * Score scale:
 *   1 = trivial (class/method renamed, 1:1 mapping)
 *   2 = easy (method removed with clear replacement)
 *   3 = medium (argument signature changed)
 *   4 = complex (hook->event migration, TCA restructure)
 *   5 = manual (architecture change without clear replacement)
 *
 * When an LLM analysis result is available, the primary score reflects the LLM
 * assessment and heuristicScore holds the rule-based score for comparison.
 */
final readonly class ComplexityScore
{
    public function __construct(
        public int $score,
        public string $reason,
        public bool $automatable,
        public ?int $heuristicScore = null,
    ) {
    }

    /**
     * Whether the primary score comes from an LLM analysis.
     */
    public function isLlmBased(): bool
    {
        return $this->heuristicScore !== null;
    }

    /**
     * Absolute difference between LLM and heuristic score, or 0 if heuristic-only.
     */
    public function scoreDivergence(): int
    {
        return $this->heuristicScore !== null
            ? abs($this->score - $this->heuristicScore)
            : 0;
    }
}
