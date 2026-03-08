<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanResult::class)]
final class ScanResultTest extends TestCase
{
    #[Test]
    public function strongFindingsReturnsOnlyStrongIndicators(): void
    {
        $result = $this->createResult();

        self::assertSame(2, $result->strongFindings());
    }

    #[Test]
    public function weakFindingsReturnsOnlyWeakIndicators(): void
    {
        $result = $this->createResult();

        self::assertSame(1, $result->weakFindings());
    }

    #[Test]
    public function uniqueRestFilesReturnsDistinctFilenames(): void
    {
        $result = $this->createResult();

        $restFiles = $result->uniqueRestFiles();

        self::assertCount(2, $restFiles);
        self::assertContains('Deprecation-12345-Foo.rst', $restFiles);
        self::assertContains('Breaking-67890-Bar.rst', $restFiles);
    }

    #[Test]
    public function findingsGroupedByRestFileReturnsCorrectGrouping(): void
    {
        $result = $this->createResult();

        $grouped = $result->findingsGroupedByRestFile();

        self::assertArrayHasKey('Deprecation-12345-Foo.rst', $grouped);
        self::assertCount(2, $grouped['Deprecation-12345-Foo.rst']);
    }

    private function createResult(): ScanResult
    {
        return new ScanResult(
            extensionPath: '/test',
            fileResults: [
                new ScanFileResult(
                    filePath: 'Classes/Foo.php',
                    findings: [
                        new ScanFinding(10, 'Deprecated class', 'strong', 'use Foo;', ['Deprecation-12345-Foo.rst']),
                        new ScanFinding(20, 'Deprecated method', 'weak', '$x->bar()', ['Deprecation-12345-Foo.rst']),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 50,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Bar.php',
                    findings: [
                        new ScanFinding(5, 'Breaking change', 'strong', 'Baz::qux()', ['Breaking-67890-Bar.rst']),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 30,
                    ignoredLines: 0,
                ),
            ],
        );
    }
}
