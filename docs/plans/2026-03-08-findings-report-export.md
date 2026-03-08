# Enhanced Findings Report & Multi-Format Export Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Enhance the scan result page with code context, grouping options, an automatable summary, and add CSV + Markdown export alongside the existing JSON export.

**Architecture:** Add a `CodeContextReader` service that reads surrounding lines from scanned files. Extend `ScanFinding` with a `contextLines` property. Add `ScanReportExporter` service with `toJson()`, `toCsv()`, `toMarkdown()` methods. Enhance the result template with grouping tabs (by file, by severity, by RST version) and a richer summary card. Add export dropdown to the result page header.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5.3

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- English PHPDoc + English inline comments

---

### Task 1: Add code context to ScanFinding

**Files:**
- Create: `src/Scanner/CodeContextReader.php`
- Create: `tests/Unit/Scanner/CodeContextReaderTest.php`
- Modify: `src/Dto/ScanFinding.php`
- Modify: `tests/Unit/Dto/ScanFindingTest.php` (if exists, otherwise verify no test breakage)

**Step 1: Create CodeContextReader with tests**

Create `tests/Unit/Scanner/CodeContextReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\CodeContextReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(CodeContextReader::class)]
final class CodeContextReaderTest extends TestCase
{
    private CodeContextReader $reader;

    private string $tmpFile;

    protected function setUp(): void
    {
        $this->reader  = new CodeContextReader();
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'ctx-');

        file_put_contents($this->tmpFile, implode("\n", [
            '<?php',                    // line 1
            '',                         // line 2
            'class Foo',                // line 3
            '{',                        // line 4
            '    public function bar()', // line 5
            '    {',                    // line 6
            '        doSomething();',   // line 7
            '    }',                    // line 8
            '}',                        // line 9
        ]));
    }

    protected function tearDown(): void
    {
        unlink($this->tmpFile);
    }

    #[Test]
    public function readContextReturnsThreeLinesBeforeAndAfter(): void
    {
        $context = $this->reader->readContext($this->tmpFile, 5, 3);

        self::assertCount(7, $context);
        self::assertSame(2, $context[0]->number);
        self::assertSame(8, $context[6]->number);
        self::assertTrue($context[3]->isHighlighted);
        self::assertFalse($context[0]->isHighlighted);
    }

    #[Test]
    public function readContextClampsAtFileStart(): void
    {
        $context = $this->reader->readContext($this->tmpFile, 1, 3);

        self::assertSame(1, $context[0]->number);
        self::assertTrue($context[0]->isHighlighted);
    }

    #[Test]
    public function readContextClampsAtFileEnd(): void
    {
        $context = $this->reader->readContext($this->tmpFile, 9, 3);

        self::assertSame(9, $context[array_key_last($context)]->number);
        self::assertTrue($context[array_key_last($context)]->isHighlighted);
    }

    #[Test]
    public function readContextReturnsEmptyForNonexistentFile(): void
    {
        $context = $this->reader->readContext('/nonexistent/file.php', 5, 3);

        self::assertSame([], $context);
    }
}
```

**Step 2: Create the ContextLine DTO**

Create `src/Dto/ContextLine.php`:

```php
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
 * A single line of source code with its line number and highlight status.
 */
final readonly class ContextLine
{
    public function __construct(
        public int $number,
        public string $content,
        public bool $isHighlighted = false,
    ) {
    }
}
```

**Step 3: Implement CodeContextReader**

Create `src/Scanner/CodeContextReader.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use App\Dto\ContextLine;
use SplFileObject;

use function is_file;
use function max;
use function min;

/**
 * Reads surrounding lines of code from a source file for context display.
 */
final class CodeContextReader
{
    /**
     * Read lines surrounding a target line number.
     *
     * @param string $filePath   Absolute path to the source file
     * @param int    $lineNumber The target line number (1-based)
     * @param int    $radius     Number of lines before and after to include
     *
     * @return list<ContextLine>
     */
    public function readContext(string $filePath, int $lineNumber, int $radius = 3): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $file      = new SplFileObject($filePath);
        $totalLines = 0;

        // Count total lines
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key() + 1;

        $startLine = max(1, $lineNumber - $radius);
        $endLine   = min($totalLines, $lineNumber + $radius);

        $lines = [];

        for ($i = $startLine; $i <= $endLine; $i++) {
            $file->seek($i - 1);
            $content = $file->current();

            $lines[] = new ContextLine(
                number: $i,
                content: rtrim((string) $content),
                isHighlighted: $i === $lineNumber,
            );
        }

        return $lines;
    }
}
```

**Step 4: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 5: Commit**

```
Add CodeContextReader for surrounding source line extraction
```

---

### Task 2: Integrate code context into ExtensionScanner

**Files:**
- Modify: `src/Dto/ScanFinding.php`
- Modify: `src/Scanner/ExtensionScanner.php`

**Step 1: Add contextLines property to ScanFinding**

Add a `contextLines` parameter to the `ScanFinding` constructor:

```php
/**
 * @param list<string>      $restFiles    RST filenames associated with this finding
 * @param list<ContextLine> $contextLines Source code lines surrounding the finding
 */
public function __construct(
    public int $line,
    public string $message,
    public string $indicator,
    public string $lineContent,
    public array $restFiles,
    public array $contextLines = [],
) {
}
```

Add `use` for `ContextLine` (not needed in the actual class since it's just used in PHPDoc, but the DTO references it).

**Step 2: Inject CodeContextReader into ExtensionScanner**

Modify `ExtensionScanner` constructor and `scanFile` method:

```php
public function __construct(
    private readonly CodeContextReader $contextReader = new CodeContextReader(),
) {
}
```

In the `scanFile` method, where findings are created (around line 178), pass context lines:

```php
$findings[] = new ScanFinding(
    line: $match['line'],
    message: $match['message'],
    indicator: $match['indicator'],
    lineContent: $this->getLineFromFile($absoluteFilePath, $match['line']),
    restFiles: $match['restFiles'] ?? [],
    contextLines: $this->contextReader->readContext($absoluteFilePath, $match['line']),
);
```

**Step 3: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass (existing tests use fixtures that produce real findings with real file paths).

**Step 4: Commit**

```
Integrate code context lines into scan findings
```

---

### Task 3: Add ScanResult summary methods

**Files:**
- Modify: `src/Dto/ScanResult.php`
- Create: `tests/Unit/Dto/ScanResultTest.php`

**Step 1: Write tests for new summary methods**

Create `tests/Unit/Dto/ScanResultTest.php`:

```php
<?php

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
```

**Step 2: Implement the summary methods on ScanResult**

Add to `src/Dto/ScanResult.php`:

```php
/**
 * Returns the count of strong (high-confidence) findings.
 */
public function strongFindings(): int
{
    $count = 0;

    foreach ($this->fileResults as $fileResult) {
        foreach ($fileResult->findings as $finding) {
            if ($finding->isStrong()) {
                $count++;
            }
        }
    }

    return $count;
}

/**
 * Returns the count of weak (low-confidence) findings.
 */
public function weakFindings(): int
{
    return $this->totalFindings() - $this->strongFindings();
}

/**
 * Returns a deduplicated list of all RST filenames referenced by findings.
 *
 * @return list<string>
 */
public function uniqueRestFiles(): array
{
    $files = [];

    foreach ($this->fileResults as $fileResult) {
        foreach ($fileResult->findings as $finding) {
            foreach ($finding->restFiles as $restFile) {
                $files[$restFile] = true;
            }
        }
    }

    return array_keys($files);
}

/**
 * Group all findings by their RST file reference.
 *
 * @return array<string, list<array{file: string, finding: ScanFinding}>>
 */
public function findingsGroupedByRestFile(): array
{
    $grouped = [];

    foreach ($this->fileResults as $fileResult) {
        foreach ($fileResult->findings as $finding) {
            foreach ($finding->restFiles as $restFile) {
                $grouped[$restFile][] = [
                    'file'    => $fileResult->filePath,
                    'finding' => $finding,
                ];
            }
        }
    }

    ksort($grouped);

    return $grouped;
}
```

Add `use function array_keys;` and `use function ksort;` imports.

**Step 3: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 4: Commit**

```
Add summary and grouping methods to ScanResult
```

---

### Task 4: Create ScanReportExporter service

**Files:**
- Create: `src/Scanner/ScanReportExporter.php`
- Create: `tests/Unit/Scanner/ScanReportExporterTest.php`

**Step 1: Write tests**

Create `tests/Unit/Scanner/ScanReportExporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use App\Scanner\ScanReportExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function str_contains;

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
        $data = json_decode($json, true);

        self::assertIsArray($data);
        self::assertSame('/test/ext', $data['extensionPath']);
        self::assertSame(2, $data['summary']['totalFindings']);
        self::assertSame(1, $data['summary']['strongFindings']);
        self::assertSame(1, $data['summary']['weakFindings']);
        self::assertSame(1, $data['summary']['filesAffected']);
        self::assertCount(1, $data['files']);
    }

    #[Test]
    public function toCsvContainsHeaderAndDataRows(): void
    {
        $result = $this->createResult();

        $csv = $this->exporter->toCsv($result);
        $lines = explode("\n", trim($csv));

        // Header + 2 data rows
        self::assertCount(3, $lines);
        self::assertSame('File,Line,Severity,Message,RST Files', $lines[0]);
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
```

**Step 2: Implement ScanReportExporter**

Create `src/Scanner/ScanReportExporter.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;

use function count;
use function implode;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Exports scan results in multiple formats: JSON, CSV, and Markdown.
 */
final class ScanReportExporter
{
    /**
     * Export scan results as structured JSON.
     */
    public function toJson(ScanResult $result): string
    {
        $data = [
            'extensionPath' => $result->extensionPath,
            'summary'       => [
                'totalFindings'  => $result->totalFindings(),
                'strongFindings' => $result->strongFindings(),
                'weakFindings'   => $result->weakFindings(),
                'scannedFiles'   => $result->scannedFiles(),
                'filesAffected'  => count($result->filesWithFindings()),
            ],
            'files' => array_map(
                static fn (ScanFileResult $fileResult): array => [
                    'file'     => $fileResult->filePath,
                    'findings' => array_map(
                        static fn (ScanFinding $finding): array => [
                            'line'      => $finding->line,
                            'message'   => $finding->message,
                            'severity'  => $finding->indicator,
                            'code'      => $finding->lineContent,
                            'restFiles' => $finding->restFiles,
                        ],
                        $fileResult->findings,
                    ),
                ],
                $result->filesWithFindings(),
            ),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Export scan results as CSV.
     */
    public function toCsv(ScanResult $result): string
    {
        $lines = ['File,Line,Severity,Message,RST Files'];

        foreach ($result->filesWithFindings() as $fileResult) {
            foreach ($fileResult->findings as $finding) {
                $lines[] = sprintf(
                    '%s,%d,%s,"%s","%s"',
                    $this->escapeCsv($fileResult->filePath),
                    $finding->line,
                    $finding->indicator,
                    $this->escapeCsv($finding->message),
                    $this->escapeCsv(implode('; ', $finding->restFiles)),
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Export scan results as Markdown.
     */
    public function toMarkdown(ScanResult $result): string
    {
        $lines = [];
        $lines[] = sprintf('# Scan Report: %s', $result->extensionPath);
        $lines[] = '';
        $lines[] = sprintf(
            '**%d** findings in **%d** files (%d scanned), **%d** strong / **%d** weak',
            $result->totalFindings(),
            count($result->filesWithFindings()),
            $result->scannedFiles(),
            $result->strongFindings(),
            $result->weakFindings(),
        );
        $lines[] = '';

        foreach ($result->filesWithFindings() as $fileResult) {
            $lines[] = sprintf('## %s', $fileResult->filePath);
            $lines[] = '';
            $lines[] = '| Line | Severity | Message | RST Files |';
            $lines[] = '|------|----------|---------|-----------|';

            foreach ($fileResult->findings as $finding) {
                $lines[] = sprintf(
                    '| %d | %s | %s | %s |',
                    $finding->line,
                    $finding->indicator,
                    $finding->message,
                    implode(', ', $finding->restFiles),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Escape a value for CSV output (double quotes).
     */
    private function escapeCsv(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
```

**Step 3: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 4: Commit**

```
Add ScanReportExporter with JSON, CSV, and Markdown export
```

---

### Task 5: Add export routes to ScanController

**Files:**
- Modify: `src/Controller/ScanController.php`

**Step 1: Replace existing `exportJson` and add CSV + Markdown routes**

Replace the `exportJson` method and `buildJsonExport` private method with three new export routes that use `ScanReportExporter`:

```php
/**
 * Export scan results as structured JSON.
 */
#[Route('/scan/export-json', name: 'scan_export_json', methods: ['GET'])]
public function exportJson(Request $request, ScanReportExporter $exporter): Response
{
    $result = $this->scanFromPath($request->query->getString('path'));

    if (!$result instanceof ScanResult) {
        return $this->redirectToRoute('scan_index');
    }

    $response = new Response($exporter->toJson($result));
    $response->headers->set('Content-Type', 'application/json');
    $response->headers->set(
        'Content-Disposition',
        HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.json'),
    );

    return $response;
}

/**
 * Export scan results as CSV.
 */
#[Route('/scan/export-csv', name: 'scan_export_csv', methods: ['GET'])]
public function exportCsv(Request $request, ScanReportExporter $exporter): Response
{
    $result = $this->scanFromPath($request->query->getString('path'));

    if (!$result instanceof ScanResult) {
        return $this->redirectToRoute('scan_index');
    }

    $response = new Response($exporter->toCsv($result));
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set(
        'Content-Disposition',
        HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.csv'),
    );

    return $response;
}

/**
 * Export scan results as Markdown.
 */
#[Route('/scan/export-markdown', name: 'scan_export_markdown', methods: ['GET'])]
public function exportMarkdown(Request $request, ScanReportExporter $exporter): Response
{
    $result = $this->scanFromPath($request->query->getString('path'));

    if (!$result instanceof ScanResult) {
        return $this->redirectToRoute('scan_index');
    }

    $response = new Response($exporter->toMarkdown($result));
    $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
    $response->headers->set(
        'Content-Disposition',
        HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.md'),
    );

    return $response;
}

/**
 * Validate path and run scan, or add flash error and return null.
 */
private function scanFromPath(string $extensionPath): ?ScanResult
{
    if ($extensionPath === '' || !is_dir($extensionPath)) {
        $this->addFlash('danger', 'Der angegebene Pfad existiert nicht oder ist kein Verzeichnis.');

        return null;
    }

    return $this->scanner->scan($extensionPath);
}
```

Remove the old `buildJsonExport` private method and the now-unused `JsonResponse` import. Add `use App\Scanner\ScanReportExporter;` import.

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 3: Commit**

```
Add CSV and Markdown export routes, refactor JSON export to use ScanReportExporter
```

---

### Task 6: Enhance result template with code context and grouping

**Files:**
- Modify: `templates/scan/result.html.twig`

**Step 1: Rewrite the result template**

Replace the entire template with enhanced version:

```twig
{% extends 'base.html.twig' %}

{% block title %}Scan-Ergebnis{% endblock %}

{% block breadcrumb %}
    <li class="breadcrumb-item"><a href="{{ path('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ path('scan_index') }}">Extension scannen</a></li>
    <li class="breadcrumb-item active">Ergebnis</li>
{% endblock %}

{% block body %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Scan-Ergebnis</h1>
        <p class="text-muted mb-0">{{ result.totalFindings() }} Fundstellen in {{ result.filesWithFindings()|length }} Dateien ({{ result.scannedFiles() }} gescannt)</p>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-download me-1"></i>Export
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="{{ path('scan_export_json', {path: result.extensionPath}) }}">
                    <i class="bi bi-filetype-json me-2"></i>JSON
                </a></li>
                <li><a class="dropdown-item" href="{{ path('scan_export_csv', {path: result.extensionPath}) }}">
                    <i class="bi bi-filetype-csv me-2"></i>CSV
                </a></li>
                <li><a class="dropdown-item" href="{{ path('scan_export_markdown', {path: result.extensionPath}) }}">
                    <i class="bi bi-markdown me-2"></i>Markdown
                </a></li>
            </ul>
        </div>
        <a href="{{ path('scan_index') }}" class="btn btn-typo3">
            <i class="bi bi-arrow-repeat me-1"></i>Neuer Scan
        </a>
    </div>
</div>

{# Summary cards #}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1">{{ result.scannedFiles() }}</div>
                <small class="text-muted">Gescannte Dateien</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1 {{ result.totalFindings() > 0 ? 'text-danger' : 'text-success' }}">{{ result.totalFindings() }}</div>
                <small class="text-muted">Fundstellen gesamt</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <span class="badge text-bg-danger me-1">{{ result.strongFindings() }} strong</span>
                <span class="badge text-bg-secondary">{{ result.weakFindings() }} weak</span>
                <br><small class="text-muted">nach Stärke</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1 {{ result.filesWithFindings()|length > 0 ? 'text-warning' : 'text-success' }}">{{ result.filesWithFindings()|length }}</div>
                <small class="text-muted">Betroffene Dateien</small>
            </div>
        </div>
    </div>
</div>

{% if result.filesWithFindings()|length == 0 %}
    <div class="alert alert-success" role="alert">
        <i class="bi bi-check-circle me-2"></i>Keine Fundstellen erkannt. Die Extension verwendet keine als deprecated oder breaking markierten APIs.
    </div>
{% else %}
    {# Grouping tabs #}
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="by-file-tab" data-bs-toggle="tab"
                    data-bs-target="#by-file-pane" type="button" role="tab">
                <i class="bi bi-file-earmark-code me-1"></i>Nach Datei
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="by-severity-tab" data-bs-toggle="tab"
                    data-bs-target="#by-severity-pane" type="button" role="tab">
                <i class="bi bi-exclamation-triangle me-1"></i>Nach Stärke
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="by-rst-tab" data-bs-toggle="tab"
                    data-bs-target="#by-rst-pane" type="button" role="tab">
                <i class="bi bi-file-text me-1"></i>Nach Deprecation
            </button>
        </li>
    </ul>

    <div class="tab-content">
        {# Group by File #}
        <div class="tab-pane fade show active" id="by-file-pane" role="tabpanel">
            {% for fileResult in result.filesWithFindings() %}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-medium"><i class="bi bi-file-earmark-code me-1"></i>{{ fileResult.filePath }}</span>
                    <span class="badge text-bg-danger">{{ fileResult.findings|length }} Fundstelle{{ fileResult.findings|length != 1 ? 'n' : '' }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Zeile</th>
                                <th>Nachricht</th>
                                <th style="width: 100px;">Stärke</th>
                                <th>RST-Dateien</th>
                            </tr>
                        </thead>
                        <tbody>
                            {% for finding in fileResult.findings %}
                            <tr>
                                <td class="text-muted">{{ finding.line }}</td>
                                <td>
                                    {{ finding.message }}
                                    {% if finding.contextLines|length > 0 %}
                                        <div class="mt-2">
                                            <pre class="bg-light border rounded p-2 mb-0 small"><code>{% for ctx in finding.contextLines %}
<span class="{{ ctx.isHighlighted ? 'bg-warning-subtle fw-bold' : 'text-muted' }}">{{ '%4d'|format(ctx.number) }} │ {{ ctx.content }}</span>
{% endfor %}</code></pre>
                                        </div>
                                    {% elseif finding.lineContent %}
                                        <br><code class="small text-muted">{{ finding.lineContent }}</code>
                                    {% endif %}
                                </td>
                                <td>
                                    {% if finding.isStrong() %}
                                        <span class="badge text-bg-danger">strong</span>
                                    {% else %}
                                        <span class="badge text-bg-secondary">weak</span>
                                    {% endif %}
                                </td>
                                <td>
                                    {% for rstFile in finding.restFiles %}
                                        <span class="badge text-bg-info mb-1">{{ rstFile }}</span>
                                    {% endfor %}
                                </td>
                            </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
            </div>
            {% endfor %}
        </div>

        {# Group by Severity #}
        <div class="tab-pane fade" id="by-severity-pane" role="tabpanel">
            {% for severity in ['strong', 'weak'] %}
                {% set severityFindings = [] %}
                {% for fileResult in result.filesWithFindings() %}
                    {% for finding in fileResult.findings %}
                        {% if finding.indicator == severity %}
                            {% set severityFindings = severityFindings|merge([{file: fileResult.filePath, finding: finding}]) %}
                        {% endif %}
                    {% endfor %}
                {% endfor %}

                {% if severityFindings|length > 0 %}
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-medium">
                            {% if severity == 'strong' %}
                                <span class="badge text-bg-danger me-1">strong</span> Hohe Konfidenz
                            {% else %}
                                <span class="badge text-bg-secondary me-1">weak</span> Niedrige Konfidenz
                            {% endif %}
                        </span>
                        <span class="badge text-bg-primary">{{ severityFindings|length }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Datei</th>
                                    <th style="width: 80px;">Zeile</th>
                                    <th>Nachricht</th>
                                    <th>RST-Dateien</th>
                                </tr>
                            </thead>
                            <tbody>
                                {% for item in severityFindings %}
                                <tr>
                                    <td><code class="small">{{ item.file }}</code></td>
                                    <td class="text-muted">{{ item.finding.line }}</td>
                                    <td>{{ item.finding.message }}</td>
                                    <td>
                                        {% for rstFile in item.finding.restFiles %}
                                            <span class="badge text-bg-info mb-1">{{ rstFile }}</span>
                                        {% endfor %}
                                    </td>
                                </tr>
                                {% endfor %}
                            </tbody>
                        </table>
                    </div>
                </div>
                {% endif %}
            {% endfor %}
        </div>

        {# Group by RST/Deprecation #}
        <div class="tab-pane fade" id="by-rst-pane" role="tabpanel">
            {% set grouped = result.findingsGroupedByRestFile() %}
            {% for rstFile, items in grouped %}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-medium"><i class="bi bi-file-text me-1"></i>{{ rstFile }}</span>
                    <span class="badge text-bg-primary">{{ items|length }} Fundstelle{{ items|length != 1 ? 'n' : '' }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Datei</th>
                                <th style="width: 80px;">Zeile</th>
                                <th style="width: 100px;">Stärke</th>
                                <th>Nachricht</th>
                            </tr>
                        </thead>
                        <tbody>
                            {% for item in items %}
                            <tr>
                                <td><code class="small">{{ item.file }}</code></td>
                                <td class="text-muted">{{ item.finding.line }}</td>
                                <td>
                                    {% if item.finding.isStrong() %}
                                        <span class="badge text-bg-danger">strong</span>
                                    {% else %}
                                        <span class="badge text-bg-secondary">weak</span>
                                    {% endif %}
                                </td>
                                <td>{{ item.finding.message }}</td>
                            </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
            </div>
            {% endfor %}
        </div>
    </div>
{% endif %}
{% endblock %}
```

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 3: Commit**

```
Enhance scan result template with code context, grouping tabs, and export dropdown
```

---

### Task 7: Code review and cleanup

**Step 1: Review all changes**

- Verify `CodeContextReader` handles edge cases (empty file, binary content)
- Check `ScanReportExporter::escapeCsv()` handles commas, newlines, and quotes in messages
- Verify template code context rendering handles whitespace/indentation correctly
- Check all new `use function` imports are present
- Verify export dropdown works (Bootstrap 5.3 requires `data-bs-toggle="dropdown"`)
- Ensure `findingsGroupedByRestFile()` handles findings with multiple RST files correctly (a finding appears in each group)
- Check `contextLines` default `[]` doesn't break existing `buildJsonExport` consumers

**Step 2: Fix CSV quoting for fields containing commas**

The `toCsv` method should wrap ALL fields in quotes for safety, or use `fputcsv` semantics. Verify the current implementation handles finding messages that contain commas.

**Step 3: Run tests and commit fixes**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`

```
Review findings: [describe fixes]
```
