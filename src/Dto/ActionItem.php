<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use function count;

/**
 * A single action in the migration plan, linking a document to scan findings
 * with matched Rector rules and automation assessment.
 */
final readonly class ActionItem
{
    /**
     * @param list<array{file: string, finding: ScanFinding}> $findings    Affected files and findings from scan
     * @param list<MigrationMapping>                          $mappings    Detected old->new mappings
     * @param list<RectorRule>                                $rectorRules Matched Rector rules
     */
    public function __construct(
        public RstDocument $document,
        public ComplexityScore $complexity,
        public array $findings,
        public array $mappings,
        public array $rectorRules,
        public AutomationGrade $automationGrade,
    ) {
    }

    /**
     * Returns the number of unique affected files.
     */
    public function affectedFileCount(): int
    {
        $files = [];

        foreach ($this->findings as $entry) {
            $files[$entry['file']] = true;
        }

        return count($files);
    }
}
