<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\ActionPlanGenerator;
use App\Analyzer\ComplexityScorer;
use App\Analyzer\MigrationMappingExtractor;
use App\Dto\AutomationGrade;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use App\Dto\ScanStatus;
use App\Generator\RectorRuleGenerator;
use App\Repository\LlmResultRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionPlanGenerator::class)]
final class ActionPlanGeneratorTest extends TestCase
{
    private ActionPlanGenerator $generator;

    protected function setUp(): void
    {
        $extractor = new MigrationMappingExtractor();

        $this->generator = new ActionPlanGenerator(
            new ComplexityScorer($extractor, new LlmResultRepository(':memory:')),
            $extractor,
            new RectorRuleGenerator($extractor),
        );
    }

    #[Test]
    public function generateReturnsEmptyPlanForNoFindings(): void
    {
        $scanResult = new ScanResult('/tmp/ext', []);

        $plan = $this->generator->generate($scanResult, []);

        self::assertSame([], $plan->items);
        self::assertSame(0, $plan->summary->totalItems);
    }

    #[Test]
    public function generateMatchesFindingsToDocuments(): void
    {
        $doc = $this->createDocument(
            filename: 'Deprecation-12345-OldClass.rst',
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $finding = new ScanFinding(
            line: 42,
            message: 'OldClass usage',
            indicator: 'strong',
            lineContent: '$obj = new OldClass();',
            restFiles: ['Deprecation-12345-OldClass.rst'],
        );

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/MyService.php', [$finding], false, 100, 0),
        ]);

        $plan = $this->generator->generate($scanResult, [$doc]);

        self::assertCount(1, $plan->items);
        self::assertSame('Deprecation-12345-OldClass.rst', $plan->items[0]->document->filename);
        self::assertSame(AutomationGrade::Full, $plan->items[0]->automationGrade);
        self::assertCount(1, $plan->items[0]->findings);
        self::assertNotEmpty($plan->items[0]->rectorRules);
    }

    #[Test]
    public function generateAssignsManualGradeWhenNoRectorRules(): void
    {
        $doc = $this->createDocument(
            filename: 'Breaking-99999-RemovedSomething.rst',
            migration: 'There is no direct replacement. Manual review required.',
        );

        $finding = new ScanFinding(10, 'Removed API', 'strong', 'code', ['Breaking-99999-RemovedSomething.rst']);

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/Legacy.php', [$finding], false, 50, 0),
        ]);

        $plan = $this->generator->generate($scanResult, [$doc]);

        self::assertCount(1, $plan->items);
        self::assertSame(AutomationGrade::Manual, $plan->items[0]->automationGrade);
    }

    #[Test]
    public function generateSortsByAutomationGradeThenFindingCount(): void
    {
        $docFull = $this->createDocument(
            filename: 'Deprecation-11111-Full.rst',
            migration: 'Replace :php:`\TYPO3\CMS\Core\A` with :php:`\TYPO3\CMS\Core\B`.',
            codeReferences: [new CodeReference('TYPO3\CMS\Core\A', null, CodeReferenceType::ClassName)],
        );

        $docManual = $this->createDocument(
            filename: 'Breaking-22222-Manual.rst',
            migration: 'No direct replacement available.',
        );

        $f1 = new ScanFinding(1, 'msg', 'strong', 'code', ['Deprecation-11111-Full.rst']);
        $f2 = new ScanFinding(2, 'msg', 'strong', 'code', ['Breaking-22222-Manual.rst']);
        $f3 = new ScanFinding(3, 'msg', 'strong', 'code', ['Breaking-22222-Manual.rst']);

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/A.php', [$f1], false, 30, 0),
            new ScanFileResult('src/B.php', [$f2], false, 30, 0),
            new ScanFileResult('src/C.php', [$f3], false, 30, 0),
        ]);

        $plan = $this->generator->generate($scanResult, [$docFull, $docManual]);

        self::assertSame(AutomationGrade::Full, $plan->items[0]->automationGrade);
        self::assertSame(AutomationGrade::Manual, $plan->items[1]->automationGrade);
    }

    #[Test]
    public function summaryCountsAreCorrect(): void
    {
        $docFull = $this->createDocument(
            filename: 'Deprecation-11111-Full.rst',
            migration: 'Replace :php:`\TYPO3\CMS\Core\A` with :php:`\TYPO3\CMS\Core\B`.',
            codeReferences: [new CodeReference('TYPO3\CMS\Core\A', null, CodeReferenceType::ClassName)],
        );

        $docManual = $this->createDocument(
            filename: 'Breaking-22222-Manual.rst',
            migration: 'No direct replacement.',
        );

        $f1 = new ScanFinding(1, 'msg', 'strong', 'code', ['Deprecation-11111-Full.rst']);
        $f2 = new ScanFinding(2, 'msg', 'strong', 'code', ['Breaking-22222-Manual.rst']);

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/A.php', [$f1, $f2], false, 50, 0),
        ]);

        $plan = $this->generator->generate($scanResult, [$docFull, $docManual]);

        self::assertSame(2, $plan->summary->totalItems);
        self::assertSame(2, $plan->summary->totalFindings);
        self::assertSame(1, $plan->summary->fullCount);
        self::assertSame(1, $plan->summary->manualCount);
        self::assertSame(0, $plan->summary->partialCount);
    }

    #[Test]
    public function generateSkipsFindingsWithoutMatchingDocument(): void
    {
        $finding = new ScanFinding(1, 'msg', 'strong', 'code', ['Unknown-99999-Missing.rst']);

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/A.php', [$finding], false, 30, 0),
        ]);

        $plan = $this->generator->generate($scanResult, []);

        self::assertSame([], $plan->items);
        self::assertSame(0, $plan->summary->totalItems);
        self::assertSame(0, $plan->summary->totalFindings);
    }

    /**
     * Create a test document with sensible defaults.
     *
     * @param list<CodeReference> $codeReferences
     */
    private function createDocument(
        string $filename = 'Deprecation-99999-Test.rst',
        ?string $migration = '',
        array $codeReferences = [],
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 99999,
            title: 'Test',
            version: '13.0',
            description: '',
            impact: null,
            migration: $migration,
            codeReferences: $codeReferences,
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
        );
    }
}
