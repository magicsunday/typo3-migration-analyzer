# Extension Scanner Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Ermöglicht das Scannen eigener TYPO3 Extensions gegen die bestehenden Matcher-Konfigurationen, um Deprecations und Breaking Changes mit Datei- und Zeilennummer-Referenzen zu finden.

**Architecture:** Ein `ExtensionScanner` Service nutzt die vorhandenen TYPO3 Matcher-Klassen (`typo3/cms-install`) als `nikic/php-parser` NodeVisitors. Der Scanner traversiert alle PHP-Dateien eines angegebenen lokalen Pfads, sammelt Findings (Datei, Zeile, Nachricht, Severity, RST-Referenz) und gibt sie als strukturierte DTOs zurück. Ein neuer `ScanController` bietet ein Eingabeformular (Pfad) und eine Ergebnis-Ansicht mit Gruppierung nach Datei, Export als JSON. Die Sidebar bekommt einen neuen Menüpunkt.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5, nikic/php-parser (transitiv via typo3/cms-install), TYPO3 ExtensionScanner Matcher-Klassen

**Kern-Mechanismus (aus TYPO3 UpgradeController reverse-engineered):**

1. PHP-Datei einlesen, mit `PhpParser\ParserFactory` parsen
2. Erster Traverser-Pass: `NameResolver` (löst `use`-Aliase zu FQCN auf)
3. Zweiter Traverser-Pass: `GeneratorClassesResolver` (löst `GeneralUtility::makeInstance('Foo')` auf) + `CodeStatistics` + alle Matcher als NodeVisitors
4. Matcher liefern `getMatches()` mit `['restFiles' => [...], 'line' => int, 'message' => string, 'indicator' => 'strong'|'weak']`

---

### Task 1: DTOs für Scan-Ergebnisse

**Files:**
- Create: `src/Dto/ScanFinding.php`
- Create: `src/Dto/ScanFileResult.php`
- Create: `src/Dto/ScanResult.php`

**Step 1: Create `ScanFinding` DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents a single finding from the extension scanner.
 */
final readonly class ScanFinding
{
    public function __construct(
        public int $line,
        public string $message,
        public string $indicator,
        public string $lineContent,
        /** @var list<string> */
        public array $restFiles,
    ) {
    }

    /**
     * Whether this finding is a strong (class-name resolved) or weak (method-name only) match.
     */
    public function isStrong(): bool
    {
        return $this->indicator === 'strong';
    }
}
```

**Step 2: Create `ScanFileResult` DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Aggregated scan results for a single PHP file.
 */
final readonly class ScanFileResult
{
    /**
     * @param list<ScanFinding> $findings
     */
    public function __construct(
        public string $filePath,
        public array $findings,
        public bool $isFileIgnored,
        public int $effectiveCodeLines,
        public int $ignoredLines,
    ) {
    }
}
```

**Step 3: Create `ScanResult` DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use function array_sum;
use function array_map;
use function count;

/**
 * Complete scan result for an extension directory.
 */
final readonly class ScanResult
{
    /**
     * @param list<ScanFileResult> $fileResults
     */
    public function __construct(
        public string $extensionPath,
        public array $fileResults,
    ) {
    }

    /**
     * Total number of findings across all files.
     */
    public function totalFindings(): int
    {
        return array_sum(array_map(
            static fn (ScanFileResult $r): int => count($r->findings),
            $this->fileResults,
        ));
    }

    /**
     * Number of scanned files (excluding ignored ones).
     */
    public function scannedFiles(): int
    {
        return count($this->fileResults);
    }

    /**
     * Only file results that have at least one finding.
     *
     * @return list<ScanFileResult>
     */
    public function filesWithFindings(): array
    {
        return array_values(array_filter(
            $this->fileResults,
            static fn (ScanFileResult $r): bool => $r->findings !== [],
        ));
    }
}
```

**Step 4: Commit**

```bash
git add src/Dto/ScanFinding.php src/Dto/ScanFileResult.php src/Dto/ScanResult.php
git commit -m "DTOs für Extension-Scanner Ergebnisse"
```

---

### Task 2: ExtensionScanner Service (TDD)

**Files:**
- Create: `src/Scanner/ExtensionScanner.php`
- Create: `tests/Unit/Scanner/ExtensionScannerTest.php`
- Create: `tests/Fixtures/Extension/` (Test-PHP-Dateien)

**Step 1: Create test fixture PHP files**

Create `tests/Fixtures/Extension/Classes/DeprecatedUsage.php`:
```php
<?php

namespace TestExtension\Classes;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class DeprecatedUsage
{
    public function doSomething(): void
    {
        // This should trigger ClassNameMatcher if the class is in the config
        $instance = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Type\Enumeration::class);
    }
}
```

Create `tests/Fixtures/Extension/Classes/CleanCode.php`:
```php
<?php

namespace TestExtension\Classes;

class CleanCode
{
    public function doSomething(): string
    {
        return 'no deprecated API usage';
    }
}
```

**Step 2: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Dto\ScanResult;
use App\Scanner\ExtensionScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

final class ExtensionScannerTest extends TestCase
{
    private ExtensionScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new ExtensionScanner();
    }

    #[Test]
    public function scanReturnsResultForValidPath(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/Extension';

        $result = $this->scanner->scan($fixturePath);

        self::assertInstanceOf(ScanResult::class, $result);
        self::assertSame($fixturePath, $result->extensionPath);
        self::assertGreaterThanOrEqual(2, $result->scannedFiles());
    }

    #[Test]
    public function scanFindsDeprecatedApiUsage(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/Extension';

        $result = $this->scanner->scan($fixturePath);
        $filesWithFindings = $result->filesWithFindings();

        // At least one file should have findings (DeprecatedUsage.php uses Enumeration)
        self::assertNotEmpty($filesWithFindings);
        self::assertGreaterThanOrEqual(1, $result->totalFindings());
    }

    #[Test]
    public function scanFindingContainsLineAndMessage(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/Extension';

        $result = $this->scanner->scan($fixturePath);
        $filesWithFindings = $result->filesWithFindings();

        self::assertNotEmpty($filesWithFindings);

        $firstFinding = $filesWithFindings[0]->findings[0];
        self::assertGreaterThan(0, $firstFinding->line);
        self::assertNotEmpty($firstFinding->message);
        self::assertNotEmpty($firstFinding->restFiles);
    }

    #[Test]
    public function scanThrowsForInvalidPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->scanner->scan('/nonexistent/path');
    }

    #[Test]
    public function scanSkipsNonPhpFiles(): void
    {
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/Extension';

        $result = $this->scanner->scan($fixturePath);

        // Only .php files should be scanned
        foreach ($result->fileResults as $fileResult) {
            self::assertStringEndsWith('.php', $fileResult->filePath);
        }
    }
}
```

**Step 3: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Scanner/ExtensionScannerTest.php --no-coverage`
Expected: FAIL with "class not found"

**Step 4: Implement ExtensionScanner**

```php
<?php

declare(strict_types=1);

namespace App\Scanner;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use InvalidArgumentException;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Install\ExtensionScanner\CodeScannerInterface;
use TYPO3\CMS\Install\ExtensionScanner\Php\CodeStatistics;
use TYPO3\CMS\Install\ExtensionScanner\Php\GeneratorClassesResolver;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayDimensionMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayGlobalMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassNameMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstructorArgumentMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\FunctionCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\InterfaceMethodChangedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentUnusedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallArgumentValueMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyExistsStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyProtectedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyPublicMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ScalarStringMatcher;

use function array_merge;
use function count;
use function dirname;
use function file_get_contents;
use function is_dir;
use function is_file;
use function sprintf;
use function str_replace;
use function substr;

/**
 * Scans PHP files of a TYPO3 extension for deprecated/removed API usage.
 *
 * Uses the TYPO3 Extension Scanner matcher classes as nikic/php-parser NodeVisitors.
 */
final class ExtensionScanner
{
    /**
     * Matcher class => config file path (relative to typo3/cms-install package).
     *
     * @var array<class-string, string>
     */
    private const array MATCHER_CONFIG = [
        ArrayDimensionMatcher::class              => 'ArrayDimensionMatcher.php',
        ArrayGlobalMatcher::class                 => 'ArrayGlobalMatcher.php',
        ClassConstantMatcher::class               => 'ClassConstantMatcher.php',
        ClassNameMatcher::class                   => 'ClassNameMatcher.php',
        ConstantMatcher::class                    => 'ConstantMatcher.php',
        ConstructorArgumentMatcher::class         => 'ConstructorArgumentMatcher.php',
        FunctionCallMatcher::class                => 'FunctionCallMatcher.php',
        InterfaceMethodChangedMatcher::class      => 'InterfaceMethodChangedMatcher.php',
        MethodAnnotationMatcher::class            => 'MethodAnnotationMatcher.php',
        MethodArgumentDroppedMatcher::class       => 'MethodArgumentDroppedMatcher.php',
        MethodArgumentDroppedStaticMatcher::class => 'MethodArgumentDroppedStaticMatcher.php',
        MethodArgumentRequiredMatcher::class      => 'MethodArgumentRequiredMatcher.php',
        MethodArgumentRequiredStaticMatcher::class => 'MethodArgumentRequiredStaticMatcher.php',
        MethodArgumentUnusedMatcher::class        => 'MethodArgumentUnusedMatcher.php',
        MethodCallMatcher::class                  => 'MethodCallMatcher.php',
        MethodCallArgumentValueMatcher::class     => 'MethodCallArgumentValueMatcher.php',
        MethodCallStaticMatcher::class            => 'MethodCallStaticMatcher.php',
        PropertyAnnotationMatcher::class          => 'PropertyAnnotationMatcher.php',
        PropertyExistsStaticMatcher::class        => 'PropertyExistsStaticMatcher.php',
        PropertyProtectedMatcher::class           => 'PropertyProtectedMatcher.php',
        PropertyPublicMatcher::class              => 'PropertyPublicMatcher.php',
        ScalarStringMatcher::class                => 'ScalarStringMatcher.php',
    ];

    /**
     * Scan all PHP files in the given extension directory.
     */
    public function scan(string $extensionPath): ScanResult
    {
        if (!is_dir($extensionPath)) {
            throw new InvalidArgumentException(
                sprintf('Extension path "%s" does not exist or is not a directory.', $extensionPath),
            );
        }

        $finder = new Finder();
        $finder->files()->in($extensionPath)->name('*.php')->sortByName();

        $fileResults = [];

        foreach ($finder as $file) {
            $absolutePath = $file->getRealPath();

            if ($absolutePath === false) {
                continue;
            }

            $fileResult = $this->scanFile($absolutePath, $extensionPath);

            if ($fileResult instanceof ScanFileResult) {
                $fileResults[] = $fileResult;
            }
        }

        return new ScanResult($extensionPath, $fileResults);
    }

    /**
     * Scan a single PHP file against all matcher configurations.
     */
    private function scanFile(string $absoluteFilePath, string $basePath): ?ScanFileResult
    {
        $code = file_get_contents($absoluteFilePath);

        if ($code === false) {
            return null;
        }

        $parser     = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));
        $statements = $parser->parse($code);

        if ($statements === null) {
            return null;
        }

        // First pass: resolve use-aliases to FQCN
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $statements = $traverser->traverse($statements);

        // Second pass: resolve makeInstance + run all matchers
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new GeneratorClassesResolver());

        $statistics = new CodeStatistics();
        $traverser->addVisitor($statistics);

        $matchers = $this->createMatchers();

        foreach ($matchers as $matcher) {
            $traverser->addVisitor($matcher);
        }

        $traverser->traverse($statements);

        // Collect findings from all matchers
        $findings = [];

        foreach ($matchers as $matcher) {
            foreach ($matcher->getMatches() as $match) {
                $findings[] = new ScanFinding(
                    line: $match['line'],
                    message: $match['message'],
                    indicator: $match['indicator'],
                    lineContent: $this->getLineFromFile($absoluteFilePath, $match['line']),
                    restFiles: $match['restFiles'],
                );
            }
        }

        $relativePath = substr($absoluteFilePath, strlen($basePath) + 1);

        return new ScanFileResult(
            filePath: $relativePath,
            findings: $findings,
            isFileIgnored: $statistics->isFileIgnored(),
            effectiveCodeLines: $statistics->getNumberOfEffectiveCodeLines(),
            ignoredLines: $statistics->getNumberOfIgnoredLines(),
        );
    }

    /**
     * Create all matcher instances from the TYPO3 config files.
     *
     * @return list<CodeScannerInterface>
     */
    private function createMatchers(): array
    {
        $configDir = $this->getConfigDirectory();
        $matchers  = [];

        foreach (self::MATCHER_CONFIG as $matcherClass => $configFile) {
            $configPath = $configDir . '/' . $configFile;

            if (!is_file($configPath)) {
                continue;
            }

            $configuration = require $configPath;

            $matchers[] = new $matcherClass($configuration);
        }

        return $matchers;
    }

    /**
     * Get the TYPO3 Extension Scanner PHP config directory.
     */
    private function getConfigDirectory(): string
    {
        // Use the reflection-based approach to find the package path
        $reflector = new \ReflectionClass(ClassNameMatcher::class);
        $matcherDir = dirname($reflector->getFileName(), 3);

        return $matcherDir . '/Configuration/ExtensionScanner/Php';
    }

    /**
     * Read a specific line from a file.
     */
    private function getLineFromFile(string $filePath, int $lineNumber): string
    {
        $file = new \SplFileObject($filePath);
        $file->seek($lineNumber - 1);

        return trim($file->current());
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Scanner/ExtensionScannerTest.php --no-coverage`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Scanner/ExtensionScanner.php tests/Unit/Scanner/ExtensionScannerTest.php tests/Fixtures/Extension/
git commit -m "ExtensionScanner Service mit TYPO3-Matcher-Integration"
```

---

### Task 3: ScanController — Formular und Scan-Aktion

**Files:**
- Create: `src/Controller/ScanController.php`
- Create: `templates/scan/index.html.twig`
- Create: `templates/scan/result.html.twig`

**Step 1: Create ScanController**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ScanResult;
use App\Scanner\ExtensionScanner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_dir;

/**
 * Controller for scanning TYPO3 extensions against deprecation matchers.
 */
final class ScanController extends AbstractController
{
    public function __construct(
        private readonly ExtensionScanner $scanner,
    ) {
    }

    #[Route('/scan', name: 'scan_index')]
    public function index(): Response
    {
        return $this->render('scan/index.html.twig');
    }

    #[Route('/scan/run', name: 'scan_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $extensionPath = $request->request->getString('extension_path');

        if ($extensionPath === '' || !is_dir($extensionPath)) {
            $this->addFlash('danger', 'Der angegebene Pfad existiert nicht oder ist kein Verzeichnis.');

            return $this->redirectToRoute('scan_index');
        }

        $result = $this->scanner->scan($extensionPath);

        return $this->render('scan/result.html.twig', [
            'result' => $result,
        ]);
    }
}
```

**Step 2: Create `templates/scan/index.html.twig`**

```twig
{% extends 'base.html.twig' %}

{% block title %}Extension scannen — {{ parent() }}{% endblock %}

{% block breadcrumb %}
    <li class="breadcrumb-item"><a href="{{ path('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Extension scannen</li>
{% endblock %}

{% block body %}
<h1 class="mb-4"><i class="bi bi-cpu me-2"></i>Extension scannen</h1>

{% for message in app.flashes('danger') %}
    <div class="alert alert-danger">{{ message }}</div>
{% endfor %}

<div class="card">
    <div class="card-body">
        <h5 class="card-title">Lokalen Pfad scannen</h5>
        <p class="card-text text-muted">
            Gib den absoluten Pfad zu einer TYPO3 Extension an. Alle PHP-Dateien werden gegen die
            bekannten Deprecation- und Breaking-Change-Matcher geprüft.
        </p>

        <form action="{{ path('scan_run') }}" method="post">
            <div class="mb-3">
                <label for="extension_path" class="form-label">Extension-Pfad</label>
                <input type="text" class="form-control" id="extension_path" name="extension_path"
                       placeholder="/var/www/html/packages/my_extension" required>
                <div class="form-text">Absoluter Pfad zum Extension-Verzeichnis.</div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-play-fill me-1"></i>Scan starten
            </button>
        </form>
    </div>
</div>
{% endblock %}
```

**Step 3: Create `templates/scan/result.html.twig`**

```twig
{% extends 'base.html.twig' %}

{% block title %}Scan-Ergebnis — {{ parent() }}{% endblock %}

{% block breadcrumb %}
    <li class="breadcrumb-item"><a href="{{ path('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ path('scan_index') }}">Extension scannen</a></li>
    <li class="breadcrumb-item active">Ergebnis</li>
{% endblock %}

{% block body %}
<h1 class="mb-4"><i class="bi bi-clipboard-data me-2"></i>Scan-Ergebnis</h1>

{# Summary #}
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-bg-primary">
            <div class="card-body text-center">
                <h3>{{ result.scannedFiles }}</h3>
                <small>Dateien gescannt</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-{{ result.totalFindings > 0 ? 'warning' : 'success' }}">
            <div class="card-body text-center">
                <h3>{{ result.totalFindings }}</h3>
                <small>Findings</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-bg-info">
            <div class="card-body text-center">
                <h3>{{ result.filesWithFindings|length }}</h3>
                <small>Dateien betroffen</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="text-muted mb-1">Pfad</h6>
                <small class="text-break">{{ result.extensionPath }}</small>
            </div>
        </div>
    </div>
</div>

{# Export #}
<div class="mb-3">
    <a href="{{ path('scan_export_json', {path: result.extensionPath}) }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download me-1"></i>JSON-Export
    </a>
    <a href="{{ path('scan_index') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-arrow-repeat me-1"></i>Neuer Scan
    </a>
</div>

{% if result.totalFindings == 0 %}
    <div class="alert alert-success">
        <i class="bi bi-check-circle me-2"></i>Keine Deprecations oder Breaking Changes gefunden.
    </div>
{% else %}
    {# Findings grouped by file #}
    {% for fileResult in result.filesWithFindings %}
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-file-earmark-code me-1"></i>
                    <strong>{{ fileResult.filePath }}</strong>
                </span>
                <span class="badge text-bg-warning">{{ fileResult.findings|length }} Finding{{ fileResult.findings|length != 1 ? 's' : '' }}</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 80px">Zeile</th>
                            <th>Nachricht</th>
                            <th style="width: 100px">Stärke</th>
                            <th>RST-Dateien</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for finding in fileResult.findings %}
                            <tr>
                                <td><code>{{ finding.line }}</code></td>
                                <td>
                                    {{ finding.message }}
                                    {% if finding.lineContent %}
                                        <br><code class="text-muted small">{{ finding.lineContent }}</code>
                                    {% endif %}
                                </td>
                                <td>
                                    <span class="badge text-bg-{{ finding.strong ? 'danger' : 'secondary' }}">
                                        {{ finding.indicator }}
                                    </span>
                                </td>
                                <td>
                                    {% for rst in finding.restFiles %}
                                        <span class="badge text-bg-light text-dark border">{{ rst }}</span>
                                    {% endfor %}
                                </td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    {% endfor %}
{% endif %}
{% endblock %}
```

**Step 4: Commit**

```bash
git add src/Controller/ScanController.php templates/scan/
git commit -m "ScanController mit Formular und Ergebnis-Ansicht"
```

---

### Task 4: JSON-Export Route

**Files:**
- Modify: `src/Controller/ScanController.php`

**Step 1: Add JSON export action**

Add to `ScanController`:

```php
#[Route('/scan/export-json', name: 'scan_export_json', methods: ['GET'])]
public function exportJson(Request $request): Response
{
    $extensionPath = $request->query->getString('path');

    if ($extensionPath === '' || !is_dir($extensionPath)) {
        throw $this->createNotFoundException('Extension path not found.');
    }

    $result = $this->scanner->scan($extensionPath);
    $data   = $this->buildJsonExport($result);

    $response = new JsonResponse($data);
    $response->headers->set(
        'Content-Disposition',
        HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.json'),
    );

    return $response;
}

/**
 * Build JSON-serializable export data.
 *
 * @return array<string, mixed>
 */
private function buildJsonExport(ScanResult $result): array
{
    $files = [];

    foreach ($result->filesWithFindings() as $fileResult) {
        $findings = [];

        foreach ($fileResult->findings as $finding) {
            $findings[] = [
                'line'        => $finding->line,
                'message'     => $finding->message,
                'indicator'   => $finding->indicator,
                'lineContent' => $finding->lineContent,
                'restFiles'   => $finding->restFiles,
            ];
        }

        $files[] = [
            'file'     => $fileResult->filePath,
            'findings' => $findings,
        ];
    }

    return [
        'extensionPath'  => $result->extensionPath,
        'scannedFiles'   => $result->scannedFiles(),
        'totalFindings'  => $result->totalFindings(),
        'filesAffected'  => count($result->filesWithFindings()),
        'files'          => $files,
    ];
}
```

**Step 2: Commit**

```bash
git add src/Controller/ScanController.php
git commit -m "JSON-Export für Scan-Ergebnisse"
```

---

### Task 5: Sidebar-Navigation erweitern

**Files:**
- Modify: `templates/base.html.twig`

**Step 1: Add sidebar entry**

Füge nach dem Matcher-Analyse-Link einen neuen Eintrag hinzu:

```twig
<li>
    <a href="{{ path('scan_index') }}" class="nav-link text-white {{ app.request.attributes.get('_route') starts with 'scan' ? 'active bg-typo3' : '' }}">
        <i class="bi bi-cpu me-2"></i>Extension scannen
    </a>
</li>
```

**Step 2: Commit**

```bash
git add templates/base.html.twig
git commit -m "Sidebar-Navigation um Extension-Scanner erweitern"
```

---

### Task 6: Integration-Test für Scanner + Controller

**Files:**
- Create: `tests/Integration/Scanner/ExtensionScannerIntegrationTest.php`

**Step 1: Write integration test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Scanner;

use App\Scanner\ExtensionScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

/**
 * Integration test that runs the full scanner pipeline against the test fixture extension.
 */
final class ExtensionScannerIntegrationTest extends TestCase
{
    #[Test]
    public function scanFixtureExtensionProducesFindings(): void
    {
        $scanner     = new ExtensionScanner();
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/Extension';

        $result = $scanner->scan($fixturePath);

        self::assertGreaterThanOrEqual(1, $result->scannedFiles());
        self::assertGreaterThanOrEqual(1, $result->totalFindings());

        // Verify finding structure
        $filesWithFindings = $result->filesWithFindings();
        self::assertNotEmpty($filesWithFindings);

        $finding = $filesWithFindings[0]->findings[0];
        self::assertGreaterThan(0, $finding->line);
        self::assertNotEmpty($finding->message);
        self::assertNotEmpty($finding->restFiles);
        self::assertNotEmpty($finding->lineContent);
    }

    #[Test]
    public function scanCleanDirectoryProducesNoFindings(): void
    {
        $scanner = new ExtensionScanner();

        // Create temp dir with clean PHP file
        $tmpDir = sys_get_temp_dir() . '/ext_scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/Clean.php', "<?php\n\nclass Clean {}\n");

        try {
            $result = $scanner->scan($tmpDir);
            self::assertSame(0, $result->totalFindings());
        } finally {
            unlink($tmpDir . '/Clean.php');
            rmdir($tmpDir);
        }
    }
}
```

**Step 2: Run all tests**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --no-coverage`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add tests/Integration/Scanner/
git commit -m "Integration-Test für Extension-Scanner"
```

---

### Task 7: Manueller Verifikationstest

**Step 1: Webseite testen**

Run: `docker compose exec phpfpm php -r "..."` oder Browser:
- `GET /scan` — Formular wird angezeigt
- `POST /scan/run` mit `extension_path=/var/www/vendor/typo3/cms-core` — scannt TYPO3 Core selbst als Integrationstest

Run:
```bash
# Test Formular-Seite
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/scan

# Test mit Fixture-Pfad
curl -s -X POST -d "extension_path=/var/www/tests/Fixtures/Extension" http://localhost:8000/scan/run | grep -c "Finding"
```

**Step 2: Run full CI**

```bash
docker compose exec phpfpm composer ci:cgl
docker compose exec phpfpm vendor/bin/phpstan analyse --no-progress --memory-limit=512M
docker compose exec phpfpm php vendor/bin/phpunit --no-coverage
```

**Step 3: Final commit and push**

```bash
git push
```
