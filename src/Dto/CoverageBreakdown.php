<?php

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
