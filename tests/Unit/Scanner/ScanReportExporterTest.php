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

    /**
     * The JSON summary and file list must reflect a result with both a strong and a weak finding.
     */
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

    /**
     * Each summary field must appear in its own designated position, using a
     * fixture with distinct counts per field so a swapped sprintf() argument
     * or a hardcoded field value would make this assertion fail.
     */
    #[Test]
    public function toTextRendersEachSummaryFieldInItsOwnPosition(): void
    {
        $result = new ScanResult(
            extensionPath: '/test/ext',
            fileResults: [
                new ScanFileResult(
                    filePath: 'Classes/Foo.php',
                    findings: [
                        new ScanFinding(10, 'a', 'strong', 'a', []),
                        new ScanFinding(11, 'b', 'strong', 'b', []),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 50,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Bar.php',
                    findings: [
                        new ScanFinding(12, 'c', 'strong', 'c', []),
                        new ScanFinding(13, 'd', 'strong', 'd', []),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 30,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Baz.php',
                    findings: [
                        new ScanFinding(20, 'e', 'weak', 'e', []),
                        new ScanFinding(21, 'f', 'weak', 'f', []),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 20,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Qux.php',
                    findings: [],
                    isFileIgnored: false,
                    effectiveCodeLines: 10,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Quux.php',
                    findings: [],
                    isFileIgnored: false,
                    effectiveCodeLines: 10,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Corge.php',
                    findings: [],
                    isFileIgnored: false,
                    effectiveCodeLines: 10,
                    ignoredLines: 0,
                ),
                new ScanFileResult(
                    filePath: 'Classes/Grault.php',
                    findings: [],
                    isFileIgnored: false,
                    effectiveCodeLines: 10,
                    ignoredLines: 0,
                ),
            ],
        );

        // Every value below is pairwise distinct (7 scanned, 6 total findings,
        // 4 strong, 2 weak, 3 files with findings — explicitly cross-checked:
        // 7 != 6 != 4 != 2 != 3 and no other pair collides either) so a
        // swapped sprintf() argument in any position produces a different,
        // non-matching string.
        $text = $this->exporter->toText($result);

        self::assertSame(
            "Scanned: /test/ext\nFiles scanned: 7\nFindings: 6 (strong: 4, weak: 2)\nFiles with findings: 3",
            $text,
        );
    }

    /**
     * A raw ESC byte in the extension path (e.g. from a maliciously named
     * scanned directory) must never reach the terminal-consumed text report.
     */
    #[Test]
    public function toTextStripsControlCharactersFromExtensionPath(): void
    {
        $result = new ScanResult(
            extensionPath: "/test/\x1Bext",
            fileResults: [],
        );

        $text = $this->exporter->toText($result);

        self::assertStringNotContainsString("\x1B", $text);
        self::assertStringContainsString('/test/ext', $text);
    }

    /**
     * The CSV export must start with the fixed header row followed by one row per finding.
     */
    #[Test]
    public function toCsvContainsHeaderAndDataRows(): void
    {
        $result = $this->createResult();

        $csv   = $this->exporter->toCsv($result);
        $lines = explode("\n", trim($csv));

        self::assertCount(3, $lines);
        self::assertSame('"File","Line","Severity","Message","RST Files"', $lines[0]);
    }

    /**
     * A raw ESC byte in a scanned file's path must never reach a CSV report
     * opened in a terminal (e.g. via `cat`) or piped through a CI log.
     */
    #[Test]
    public function toCsvStripsControlCharactersFromFilePath(): void
    {
        $result = new ScanResult(
            extensionPath: '/test/ext',
            fileResults: [
                new ScanFileResult(
                    filePath: "Classes/\x1BFoo.php",
                    findings: [
                        new ScanFinding(10, 'Deprecated class usage', 'strong', 'use Foo;', []),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 50,
                    ignoredLines: 0,
                ),
            ],
        );

        $csv = $this->exporter->toCsv($result);

        self::assertStringNotContainsString("\x1B", $csv);
        self::assertStringContainsString('Classes/Foo.php', $csv);
    }

    /**
     * The Markdown export must contain the report heading, the summary line,
     * a per-file heading, and the findings table header.
     */
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

    /**
     * A raw ESC byte in either the extension path or a scanned file's path
     * must never reach a Markdown report consumed on a terminal.
     */
    #[Test]
    public function toMarkdownStripsControlCharactersFromExtensionPathAndFilePath(): void
    {
        $result = new ScanResult(
            extensionPath: "/test/\x1Bext",
            fileResults: [
                new ScanFileResult(
                    filePath: "Classes/\x1BFoo.php",
                    findings: [
                        new ScanFinding(10, 'Deprecated class usage', 'strong', 'use Foo;', []),
                    ],
                    isFileIgnored: false,
                    effectiveCodeLines: 50,
                    ignoredLines: 0,
                ),
            ],
        );

        $md = $this->exporter->toMarkdown($result);

        self::assertStringNotContainsString("\x1B", $md);
        self::assertStringContainsString('/test/ext', $md);
        self::assertStringContainsString('Classes/Foo.php', $md);
    }

    /**
     * Build a scan result with one strong and one weak finding in a single file.
     */
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
