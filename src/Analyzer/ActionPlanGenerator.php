<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\ActionItem;
use App\Dto\ActionPlan;
use App\Dto\ActionPlanSummary;
use App\Dto\AutomationGrade;
use App\Dto\RectorRule;
use App\Dto\RstDocument;
use App\Dto\ScanResult;
use App\Generator\RectorRuleGenerator;

use function array_filter;
use function array_values;
use function count;
use function usort;

/**
 * Generates a prioritized migration action plan from scan results and RST documents.
 *
 * Correlates scan findings with their referenced RST documents, resolves
 * mappings and Rector rules, assigns an automation grade, and sorts by priority.
 */
final readonly class ActionPlanGenerator
{
    public function __construct(
        private ComplexityScorer $complexityScorer,
        private MigrationMappingExtractor $mappingExtractor,
        private RectorRuleGenerator $rectorGenerator,
    ) {
    }

    /**
     * Generate a prioritized action plan from scan results and available documents.
     *
     * @param list<RstDocument> $documents All parsed RST documents for the current version range
     */
    public function generate(ScanResult $scanResult, array $documents): ActionPlan
    {
        // Build lookup: RST filename -> RstDocument
        $docByFilename = [];

        foreach ($documents as $doc) {
            $docByFilename[$doc->filename] = $doc;
        }

        // Group scan findings by RST file
        $findingsByRst = $scanResult->findingsGroupedByRestFile();

        // Build action items for each referenced RST document
        $items         = [];
        $fullCount     = 0;
        $partialCount  = 0;
        $manualCount   = 0;
        $totalFindings = 0;

        foreach ($findingsByRst as $rstFilename => $findingEntries) {
            if (!isset($docByFilename[$rstFilename])) {
                continue;
            }

            $doc         = $docByFilename[$rstFilename];
            $complexity  = $this->complexityScorer->score($doc);
            $mappings    = $this->mappingExtractor->extract($doc->migration, $doc->description);
            $rules       = $this->rectorGenerator->generate($doc);
            $configRules = array_values(array_filter(
                $rules,
                static fn (RectorRule $r): bool => $r->isConfig(),
            ));

            $grade = $this->determineGrade($configRules, $rules);

            $items[] = new ActionItem(
                document: $doc,
                complexity: $complexity,
                findings: $findingEntries,
                mappings: $mappings,
                rectorRules: $rules,
                automationGrade: $grade,
            );

            $totalFindings += count($findingEntries);

            match ($grade) {
                AutomationGrade::Full    => ++$fullCount,
                AutomationGrade::Partial => ++$partialCount,
                AutomationGrade::Manual  => ++$manualCount,
            };
        }

        // Sort: Full first, then Partial, then Manual; within same grade by finding count desc
        usort($items, static function (ActionItem $a, ActionItem $b): int {
            $gradeOrder = [
                AutomationGrade::Full->value    => 0,
                AutomationGrade::Partial->value => 1,
                AutomationGrade::Manual->value  => 2,
            ];

            $gradeCompare = $gradeOrder[$a->automationGrade->value] <=> $gradeOrder[$b->automationGrade->value];

            if ($gradeCompare !== 0) {
                return $gradeCompare;
            }

            return count($b->findings) <=> count($a->findings);
        });

        return new ActionPlan(
            items: $items,
            summary: new ActionPlanSummary(
                totalItems: count($items),
                totalFindings: $totalFindings,
                fullCount: $fullCount,
                partialCount: $partialCount,
                manualCount: $manualCount,
            ),
        );
    }

    /**
     * Determine the automation grade based on available Rector rules.
     *
     * @param list<RectorRule> $configRules Rules that generate Rector config entries
     * @param list<RectorRule> $allRules    All rules including skeletons
     */
    private function determineGrade(array $configRules, array $allRules): AutomationGrade
    {
        if ($configRules !== [] && count($configRules) === count($allRules)) {
            return AutomationGrade::Full;
        }

        if ($configRules !== []) {
            return AutomationGrade::Partial;
        }

        return AutomationGrade::Manual;
    }
}
