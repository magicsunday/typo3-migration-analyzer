<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use function array_filter;
use function array_values;
use function ksort;

/**
 * Complete migration action plan with prioritized items and summary.
 */
final readonly class ActionPlan
{
    /**
     * @param list<ActionItem> $items Prioritized action items
     */
    public function __construct(
        public array $items,
        public ActionPlanSummary $summary,
    ) {
    }

    /**
     * Filter items by automation grade.
     *
     * @return list<ActionItem>
     */
    public function itemsByGrade(AutomationGrade $grade): array
    {
        return array_values(
            array_filter(
                $this->items,
                static fn (ActionItem $item): bool => $item->automationGrade === $grade,
            ),
        );
    }

    /**
     * Group items by affected file path.
     *
     * @return array<string, list<ActionItem>>
     */
    public function itemsByFile(): array
    {
        $grouped = [];

        foreach ($this->items as $item) {
            foreach ($item->findings as $entry) {
                $grouped[$entry['file']][] = $item;
            }
        }

        // Deduplicate: same item may appear multiple times per file
        foreach ($grouped as $file => $items) {
            $seen   = [];
            $unique = [];

            foreach ($items as $item) {
                $key = $item->document->filename;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $unique[]   = $item;
            }

            $grouped[$file] = $unique;
        }

        ksort($grouped);

        return $grouped;
    }
}
