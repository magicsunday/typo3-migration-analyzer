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
 * Summary statistics for an action plan.
 */
final readonly class ActionPlanSummary
{
    public function __construct(
        public int $totalItems,
        public int $totalFindings,
        public int $fullCount,
        public int $partialCount,
        public int $manualCount,
    ) {
    }
}
