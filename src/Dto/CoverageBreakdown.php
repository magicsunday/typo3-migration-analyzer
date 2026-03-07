<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

final readonly class CoverageBreakdown
{
    public function __construct(
        public string $label,
        public int $total,
        public int $covered,
        public float $percent,
    ) {
    }
}
