<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

final readonly class CoverageResult
{
    /**
     * @param RstDocument[]       $covered
     * @param RstDocument[]       $uncovered
     * @param CoverageBreakdown[] $byVersion
     * @param CoverageBreakdown[] $byType
     */
    public function __construct(
        public array $covered,
        public array $uncovered,
        public float $coveragePercent,
        public int $totalDocuments,
        public int $totalMatchers,
        public array $byVersion = [],
        public array $byType = [],
    ) {
    }
}
