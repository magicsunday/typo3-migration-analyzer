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
 * Represents the complexity assessment of a migration document.
 *
 * Score scale:
 *   1 = trivial (class/method renamed, 1:1 mapping)
 *   2 = easy (method removed with clear replacement)
 *   3 = medium (argument signature changed)
 *   4 = complex (hook->event migration, TCA restructure)
 *   5 = manual (architecture change without clear replacement)
 */
final readonly class ComplexityScore
{
    public function __construct(
        public int $score,
        public string $reason,
        public bool $automatable,
    ) {
    }
}
