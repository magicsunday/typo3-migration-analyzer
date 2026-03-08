<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ActionItem;
use App\Dto\ActionPlan;
use App\Dto\ActionPlanSummary;
use App\Dto\AutomationGrade;
use App\Dto\ComplexityScore;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanFinding;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionPlan::class)]
final class ActionPlanTest extends TestCase
{
    #[Test]
    public function itemsByGradeFiltersCorrectly(): void
    {
        $plan = new ActionPlan(
            items: [
                $this->createActionItem(AutomationGrade::Full),
                $this->createActionItem(AutomationGrade::Manual),
                $this->createActionItem(AutomationGrade::Full),
            ],
            summary: new ActionPlanSummary(3, 3, 2, 0, 1),
        );

        self::assertCount(2, $plan->itemsByGrade(AutomationGrade::Full));
        self::assertCount(1, $plan->itemsByGrade(AutomationGrade::Manual));
        self::assertCount(0, $plan->itemsByGrade(AutomationGrade::Partial));
    }

    #[Test]
    public function itemsByFileGroupsCorrectly(): void
    {
        $finding1 = new ScanFinding(10, 'msg', 'strong', 'code', ['Doc.rst']);
        $finding2 = new ScanFinding(20, 'msg', 'strong', 'code', ['Doc.rst']);

        $item1 = $this->createActionItem(AutomationGrade::Full, [
            ['file' => 'src/Foo.php', 'finding' => $finding1],
        ]);

        $item2 = $this->createActionItem(AutomationGrade::Manual, [
            ['file' => 'src/Foo.php', 'finding' => $finding2],
            ['file' => 'src/Bar.php', 'finding' => $finding2],
        ]);

        $plan = new ActionPlan(
            items: [$item1, $item2],
            summary: new ActionPlanSummary(2, 3, 1, 0, 1),
        );

        $byFile = $plan->itemsByFile();

        self::assertCount(2, $byFile);
        self::assertCount(2, $byFile['src/Foo.php']);
        self::assertCount(1, $byFile['src/Bar.php']);
    }

    /**
     * @param list<array{file: string, finding: ScanFinding}> $findings
     */
    private function createActionItem(
        AutomationGrade $grade,
        array $findings = [],
    ): ActionItem {
        if ($findings === []) {
            $findings = [
                ['file' => 'src/Default.php', 'finding' => new ScanFinding(1, 'msg', 'strong', 'code', ['Doc.rst'])],
            ];
        }

        return new ActionItem(
            document: new RstDocument(
                type: DocumentType::Deprecation,
                issueId: 99999,
                title: 'Test',
                version: '13.0',
                description: '',
                impact: null,
                migration: null,
                codeReferences: [],
                indexTags: [],
                scanStatus: ScanStatus::NotScanned,
                filename: 'Deprecation-99999-Test-' . $grade->value . '.rst',
            ),
            complexity: new ComplexityScore(1, 'test', true),
            findings: $findings,
            mappings: [],
            rectorRules: [],
            automationGrade: $grade,
        );
    }
}
