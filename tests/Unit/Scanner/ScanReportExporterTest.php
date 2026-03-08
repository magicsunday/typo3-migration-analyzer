<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use App\Scanner\ScanReportExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function explode;
use function json_decode;
use function str_contains;
use function trim;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ScanReportExporter::class)]
final class ScanReportExporterTest extends TestCase
{
    private ScanReportExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new ScanReportExporter();
    }

    #[Test]
    public function toJsonReturnsValidJson(): void
    {
        $result = $this->createResult();

        $json = $this->exporter->toJson($result);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('/test/ext', $data['extensionPath']);

        /** @var array<string, int> $summary */
        $summary = $data['summary'];
        self::assertSame(2, $summary['totalFindings']);
        self::assertSame(1, $summary['strongFindings']);
        self::assertSame(1, $summary['weakFindings']);
        self::assertSame(1, $summary['filesAffected']);

        /** @var list<array<string, mixed>> $files */
        $files = $data['files'];
        self::assertCount(1, $files);
    }

    #[Test]
    public function toCsvContainsHeaderAndDataRows(): void
    {
        $result = $this->createResult();

        $csv   = $this->exporter->toCsv($result);
        $lines = explode("\n", trim($csv));

        self::assertCount(3, $lines);
        self::assertSame('"File","Line","Severity","Message","RST Files"', $lines[0]);
    }

    #[Test]
    public function toMarkdownContainsSummaryAndTable(): void
    {
        $result = $this->createResult();

        $md = $this->exporter->toMarkdown($result);

        self::assertTrue(str_contains($md, '# Scan Report'));
        self::assertTrue(str_contains($md, '**2** findings'));
        self::assertTrue(str_contains($md, 'Classes/Foo.php'));
        self::assertTrue(str_contains($md, '| Line | Severity | Message | RST Files |'));
    }

    private function createResult(): ScanResult
    {
        return new ScanResult(
            extensionPath: '/test/ext',
            fileResults: [
                new ScanFileResult(
                    filePath: 'Classes/Foo.php',
                    findings: [
                        new ScanFinding(10, 'Deprecated class usage', 'strong', 'use Foo;', ['Deprecation-12345-Foo.rst']),
                        new ScanFinding(20, 'Deprecated method call', 'weak', '$x->bar()', ['Breaking-67890-Bar.rst']),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 50,
                    ignoredLines: 0,
                ),
            ],
        );
    }
}
