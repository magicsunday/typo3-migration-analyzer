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
use App\Dto\AutomationGrade;
use App\Dto\ComplexityScore;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanFinding;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionItem::class)]
final class ActionItemTest extends TestCase
{
    #[Test]
    public function affectedFileCountDeduplicatesFiles(): void
    {
        $finding1 = new ScanFinding(10, 'msg1', 'strong', 'code1', ['Doc.rst']);
        $finding2 = new ScanFinding(20, 'msg2', 'strong', 'code2', ['Doc.rst']);
        $finding3 = new ScanFinding(5, 'msg3', 'weak', 'code3', ['Doc.rst']);

        $item = new ActionItem(
            document: $this->createDocument(),
            complexity: new ComplexityScore(1, 'test', true),
            findings: [
                ['file' => 'src/Foo.php', 'finding' => $finding1],
                ['file' => 'src/Foo.php', 'finding' => $finding2],
                ['file' => 'src/Bar.php', 'finding' => $finding3],
            ],
            mappings: [],
            rectorRules: [],
            automationGrade: AutomationGrade::Full,
        );

        self::assertSame(2, $item->affectedFileCount());
    }

    private function createDocument(): RstDocument
    {
        return new RstDocument(
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
            filename: 'Deprecation-99999-Test.rst',
        );
    }
}
