<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class CoverageResult
{
    /**
     * @param RstDocument[] $covered
     * @param RstDocument[] $uncovered
     */
    public function __construct(
        public array $covered,
        public array $uncovered,
        public float $coveragePercent,
        public int $totalDocuments,
        public int $totalMatchers,
    ) {
    }
}
