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
 * Represents an LLM assessment of Rector automation feasibility.
 */
final readonly class LlmRectorAssessment
{
    /**
     * @param bool        $feasible Whether a Rector rule can handle this migration
     * @param string|null $ruleType Suggested Rector rule type (e.g. "RenameClassRector"), or null
     * @param string      $notes    Explanation of automation limitations or edge cases
     */
    public function __construct(
        public bool $feasible,
        public ?string $ruleType,
        public string $notes,
    ) {
    }
}
