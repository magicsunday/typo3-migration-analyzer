# Coverage-Report Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Eigene Coverage-Report-Seite mit detaillierter prozentualer Aufschlüsselung nach Version, Typ, ScanStatus und Matcher-Typ — als dedizierte Route `/coverage` mit interaktiver Darstellung.

**Architecture:** Erweitert `CoverageResult` um zwei neue Breakdowns (`byScanStatus`, `byMatcherType`). Der Analyzer berechnet alle Dimensionen. Ein neuer `CoverageController` liefert die Daten an ein dediziertes Template mit Fortschrittsbalken, Tabellen und Detailansicht pro Breakdown-Gruppe.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5.3

---

### Task 1: CoverageResult um byScanStatus erweitern

**Files:**
- Modify: `src/Dto/CoverageResult.php`
- Modify: `src/Analyzer/MatcherCoverageAnalyzer.php`
- Modify: `tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php`

**Step 1: Write the failing test**

In `tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php` hinzufügen:

```php
#[Test]
public function analyzeBuildsScanStatusBreakdown(): void
{
    $fullyScanned = new RstDocument(
        type: DocumentType::Deprecation,
        issueId: 1,
        title: 'Fully scanned',
        version: '13.0',
        description: 'Test',
        impact: null,
        migration: null,
        codeReferences: [],
        indexTags: [],
        scanStatus: ScanStatus::FullyScanned,
        filename: 'Deprecation-11111-FullyScanned.rst',
    );

    $notScanned = new RstDocument(
        type: DocumentType::Breaking,
        issueId: 2,
        title: 'Not scanned',
        version: '13.0',
        description: 'Test',
        impact: null,
        migration: null,
        codeReferences: [],
        indexTags: [],
        scanStatus: ScanStatus::NotScanned,
        filename: 'Breaking-22222-NotScanned.rst',
    );

    $matcher = new MatcherEntry(
        identifier: 'TYPO3\CMS\Core\Foo->bar',
        matcherType: MatcherType::MethodCall,
        restFiles: ['Deprecation-11111-FullyScanned.rst'],
    );

    $result = $this->analyzer->analyze([$fullyScanned, $notScanned], [$matcher]);

    self::assertNotEmpty($result->byScanStatus);
    self::assertCount(2, $result->byScanStatus);

    // Find the FullyScanned breakdown
    $fullyScannedBreakdown = null;
    foreach ($result->byScanStatus as $breakdown) {
        if ($breakdown->label === 'Fully scanned') {
            $fullyScannedBreakdown = $breakdown;
        }
    }

    self::assertNotNull($fullyScannedBreakdown);
    self::assertSame(1, $fullyScannedBreakdown->total);
    self::assertSame(1, $fullyScannedBreakdown->covered);
    self::assertSame(100.0, $fullyScannedBreakdown->percent);
}
```

**Step 2: Run test to verify it fails**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 php vendor/bin/phpunit tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php --filter analyzeBuildsScanStatusBreakdown -v`
Expected: FAIL — property `byScanStatus` does not exist

**Step 3: Add `byScanStatus` to CoverageResult**

In `src/Dto/CoverageResult.php`, add parameter:

```php
/**
 * @param RstDocument[]       $covered
 * @param RstDocument[]       $uncovered
 * @param CoverageBreakdown[] $byVersion
 * @param CoverageBreakdown[] $byType
 * @param CoverageBreakdown[] $byScanStatus
 */
public function __construct(
    public array $covered,
    public array $uncovered,
    public float $coveragePercent,
    public int $totalDocuments,
    public int $totalMatchers,
    public array $byVersion = [],
    public array $byType = [],
    public array $byScanStatus = [],
) {
}
```

**Step 4: Add byScanStatus calculation to MatcherCoverageAnalyzer**

In `src/Analyzer/MatcherCoverageAnalyzer.php`, in der `analyze()` Methode nach Zeile 63 hinzufügen:

```php
$byScanStatus = $this->buildBreakdown($documents, $referencedFiles, 'scanStatus');
```

Und im `return new CoverageResult(...)` ergänzen:

```php
byScanStatus: $byScanStatus,
```

In der `buildBreakdown()` Methode die Key-Ermittlung erweitern (Zeile 90-92):

```php
$key = match ($property) {
    'type'       => ucfirst($document->type->value),
    'scanStatus' => ucfirst(str_replace('_', ' ', $document->scanStatus->value)),
    default      => $document->version,
};
```

Dazu `use function str_replace;` am Anfang der Datei hinzufügen.

**Step 5: Run test to verify it passes**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 php vendor/bin/phpunit tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php -v`
Expected: ALL PASS

**Step 6: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 7: Commit**

```bash
git add src/Dto/CoverageResult.php src/Analyzer/MatcherCoverageAnalyzer.php tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php
git commit -m "CoverageResult um byScanStatus-Breakdown erweitern"
```

---

### Task 2: CoverageResult um byMatcherType erweitern

**Files:**
- Modify: `src/Dto/CoverageResult.php`
- Modify: `src/Analyzer/MatcherCoverageAnalyzer.php`
- Modify: `tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php`

**Step 1: Write the failing test**

In `tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php` hinzufügen:

```php
#[Test]
public function analyzeBuildsMatcherTypeBreakdown(): void
{
    $doc1 = $this->createDocument('Deprecation-11111-A.rst');
    $doc2 = $this->createDocument('Deprecation-22222-B.rst');

    $methodMatcher = new MatcherEntry(
        identifier: 'TYPO3\CMS\Core\Foo->bar',
        matcherType: MatcherType::MethodCall,
        restFiles: ['Deprecation-11111-A.rst'],
    );

    $classMatcher = new MatcherEntry(
        identifier: 'TYPO3\CMS\Core\Baz',
        matcherType: MatcherType::ClassName,
        restFiles: ['Deprecation-22222-B.rst'],
    );

    $result = $this->analyzer->analyze([$doc1, $doc2], [$methodMatcher, $classMatcher]);

    self::assertNotEmpty($result->byMatcherType);

    // Each matcher type should have count = 1
    $methodBreakdown = null;
    $classBreakdown  = null;

    foreach ($result->byMatcherType as $breakdown) {
        if ($breakdown->label === 'MethodCallMatcher') {
            $methodBreakdown = $breakdown;
        }

        if ($breakdown->label === 'ClassNameMatcher') {
            $classBreakdown = $breakdown;
        }
    }

    self::assertNotNull($methodBreakdown);
    self::assertSame(1, $methodBreakdown->total);

    self::assertNotNull($classBreakdown);
    self::assertSame(1, $classBreakdown->total);
}
```

**Step 2: Run test to verify it fails**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 php vendor/bin/phpunit tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php --filter analyzeBuildsMatcherTypeBreakdown -v`
Expected: FAIL — property `byMatcherType` does not exist

**Step 3: Add `byMatcherType` to CoverageResult**

In `src/Dto/CoverageResult.php`, Parameter hinzufügen:

```php
/**
 * @param RstDocument[]       $covered
 * @param RstDocument[]       $uncovered
 * @param CoverageBreakdown[] $byVersion
 * @param CoverageBreakdown[] $byType
 * @param CoverageBreakdown[] $byScanStatus
 * @param CoverageBreakdown[] $byMatcherType
 */
public function __construct(
    public array $covered,
    public array $uncovered,
    public float $coveragePercent,
    public int $totalDocuments,
    public int $totalMatchers,
    public array $byVersion = [],
    public array $byType = [],
    public array $byScanStatus = [],
    public array $byMatcherType = [],
) {
}
```

**Step 4: Add byMatcherType calculation to MatcherCoverageAnalyzer**

Die `byMatcherType`-Berechnung ist anders als die anderen — sie gruppiert nicht nach einer Dokument-Property, sondern nach dem MatcherType der Matcher-Einträge, die ein Dokument abdecken. Neue Methode in `MatcherCoverageAnalyzer`:

```php
/**
 * Build coverage breakdown by matcher type.
 *
 * Groups matcher entries by their MatcherType and counts how many unique
 * RST documents each type covers.
 *
 * @param MatcherEntry[] $matchers
 *
 * @return list<CoverageBreakdown>
 */
private function buildMatcherTypeBreakdown(array $matchers): array
{
    /** @var array<string, array<string, true>> $byType */
    $byType = [];

    foreach ($matchers as $matcher) {
        $type = $matcher->matcherType->value;

        if (!isset($byType[$type])) {
            $byType[$type] = [];
        }

        foreach ($matcher->restFiles as $restFile) {
            $byType[$type][$restFile] = true;
        }
    }

    ksort($byType);

    $breakdowns = [];

    foreach ($byType as $label => $files) {
        $breakdowns[] = new CoverageBreakdown(
            label: $label,
            total: count($files),
            covered: count($files),
            percent: 100.0,
        );
    }

    return $breakdowns;
}
```

In der `analyze()` Methode aufrufen:

```php
$byMatcherType = $this->buildMatcherTypeBreakdown($matchers);
```

Und im `return new CoverageResult(...)`:

```php
byMatcherType: $byMatcherType,
```

**Step 5: Run test to verify it passes**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 php vendor/bin/phpunit tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php -v`
Expected: ALL PASS

**Step 6: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 7: Commit**

```bash
git add src/Dto/CoverageResult.php src/Analyzer/MatcherCoverageAnalyzer.php tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php
git commit -m "CoverageResult um byMatcherType-Breakdown erweitern"
```

---

### Task 3: CoverageController und Route anlegen

**Files:**
- Create: `src/Controller/CoverageController.php`
- Create: `templates/coverage/index.html.twig`

**Step 1: Create Controller**

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Coverage report with detailed breakdown by version, type, scan status and matcher type.
 */
final class CoverageController extends AbstractController
{
    #[Route('/coverage', name: 'coverage_report')]
    public function index(DocumentService $documentService): Response
    {
        return $this->render('coverage/index.html.twig', [
            'coverage' => $documentService->getCoverage(),
        ]);
    }
}
```

**Step 2: Create minimal Template**

```twig
{% extends 'base.html.twig' %}

{% block title %}Coverage-Report{% endblock %}

{% block breadcrumb %}
    <li class="breadcrumb-item"><a href="{{ path('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Coverage-Report</li>
{% endblock %}

{% block body %}
<div class="mb-4">
    <h1 class="h3 mb-1">Coverage-Report</h1>
    <p class="text-muted mb-0">Detaillierte Aufschlüsselung der Matcher-Abdeckung</p>
</div>

{# Overall coverage #}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="card-title mb-1">Gesamtabdeckung</h5>
                <span class="text-muted">{{ coverage.covered|length }} von {{ coverage.totalDocuments }} Dokumenten abgedeckt</span>
            </div>
            <div class="h2 mb-0 {{ coverage.coveragePercent >= 80 ? 'text-success' : (coverage.coveragePercent >= 50 ? 'text-warning' : 'text-danger') }}">
                {{ coverage.coveragePercent|number_format(1) }}%
            </div>
        </div>
        <div class="progress progress-thick">
            <div class="progress-bar {{ coverage.coveragePercent >= 80 ? 'bg-success' : (coverage.coveragePercent >= 50 ? 'bg-warning' : 'bg-danger') }}"
                 style="width: {{ coverage.coveragePercent }}%"></div>
        </div>
    </div>
</div>

<div class="row g-4">
    {# By Version #}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent">
                <h6 class="card-title mb-0"><i class="bi bi-tag me-1"></i>Nach Version</h6>
            </div>
            <div class="card-body">
                {% for breakdown in coverage.byVersion %}
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-medium">{{ breakdown.label }}</span>
                        <span class="text-muted">{{ breakdown.covered }}/{{ breakdown.total }} ({{ breakdown.percent|number_format(1) }}%)</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar {{ breakdown.percent >= 80 ? 'bg-success' : (breakdown.percent >= 50 ? 'bg-warning' : 'bg-danger') }}"
                             style="width: {{ breakdown.percent }}%"></div>
                    </div>
                </div>
                {% endfor %}
            </div>
        </div>
    </div>

    {# By Type #}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent">
                <h6 class="card-title mb-0"><i class="bi bi-collection me-1"></i>Nach Typ</h6>
            </div>
            <div class="card-body">
                {% for breakdown in coverage.byType %}
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-medium">{{ breakdown.label }}</span>
                        <span class="text-muted">{{ breakdown.covered }}/{{ breakdown.total }} ({{ breakdown.percent|number_format(1) }}%)</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar {{ breakdown.percent >= 80 ? 'bg-success' : (breakdown.percent >= 50 ? 'bg-warning' : 'bg-danger') }}"
                             style="width: {{ breakdown.percent }}%"></div>
                    </div>
                </div>
                {% endfor %}
            </div>
        </div>
    </div>

    {# By Scan Status #}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent">
                <h6 class="card-title mb-0"><i class="bi bi-shield-check me-1"></i>Nach Scan-Status</h6>
            </div>
            <div class="card-body">
                {% for breakdown in coverage.byScanStatus %}
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-medium">{{ breakdown.label }}</span>
                        <span class="text-muted">{{ breakdown.covered }}/{{ breakdown.total }} ({{ breakdown.percent|number_format(1) }}%)</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar {{ breakdown.percent >= 80 ? 'bg-success' : (breakdown.percent >= 50 ? 'bg-warning' : 'bg-danger') }}"
                             style="width: {{ breakdown.percent }}%"></div>
                    </div>
                </div>
                {% endfor %}
            </div>
        </div>
    </div>

    {# By Matcher Type #}
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent">
                <h6 class="card-title mb-0"><i class="bi bi-puzzle me-1"></i>Nach Matcher-Typ</h6>
            </div>
            <div class="card-body">
                {% for breakdown in coverage.byMatcherType %}
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-medium">{{ breakdown.label }}</span>
                        <span class="text-muted">{{ breakdown.total }} Dokumente</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-primary" style="width: {{ (breakdown.total / coverage.totalDocuments * 100)|number_format(0) }}%"></div>
                    </div>
                </div>
                {% endfor %}
            </div>
        </div>
    </div>
</div>

{# Summary stats #}
<div class="row g-3 mt-2">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="h3 mb-1 text-primary">{{ coverage.totalDocuments }}</div>
                <div class="text-muted small">Dokumente gesamt</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="h3 mb-1 text-success">{{ coverage.covered|length }}</div>
                <div class="text-muted small">Abgedeckt</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="h3 mb-1 text-danger">{{ coverage.uncovered|length }}</div>
                <div class="text-muted small">Nicht abgedeckt</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body">
                <div class="h3 mb-1 text-info">{{ coverage.totalMatchers }}</div>
                <div class="text-muted small">Matcher-Einträge</div>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

**Step 3: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 4: Commit**

```bash
git add src/Controller/CoverageController.php templates/coverage/index.html.twig
git commit -m "Coverage-Report Controller und Template anlegen"
```

---

### Task 4: Sidebar-Navigation um Coverage-Report erweitern

**Files:**
- Modify: `templates/base.html.twig`

**Step 1: Add navigation entry**

In `templates/base.html.twig` nach dem Matcher-Analyse `<li>` (ca. Zeile 39) und vor dem Extension-scannen `<li>` einfügen:

```twig
<li>
    <a href="{{ path('coverage_report') }}" class="nav-link text-white {{ app.request.attributes.get('_route') starts with 'coverage' ? 'active bg-typo3' : '' }}">
        <i class="bi bi-bar-chart me-2"></i>Coverage-Report
    </a>
</li>
```

**Step 2: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 3: Commit**

```bash
git add templates/base.html.twig
git commit -m "Sidebar-Navigation um Coverage-Report erweitern"
```

---

### Task 5: Uncovered-Dokumente-Tabelle im Coverage-Report

**Files:**
- Modify: `templates/coverage/index.html.twig`

**Step 1: Add uncovered documents table**

Am Ende des Templates (vor `{% endblock %}`) eine aufklappbare Tabelle der nicht abgedeckten Dokumente hinzufügen:

```twig
{# Uncovered documents detail #}
<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="bi bi-x-circle me-1"></i>Nicht abgedeckte Dokumente
            <span class="badge rounded-pill text-bg-danger ms-1">{{ coverage.uncovered|length }}</span>
        </h6>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#uncoveredTable">
            <i class="bi bi-chevron-down"></i>
        </button>
    </div>
    <div class="collapse" id="uncoveredTable">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Typ</th>
                        <th>Version</th>
                        <th>Titel</th>
                        <th>Scan-Status</th>
                        <th class="text-center">Referenzen</th>
                    </tr>
                </thead>
                <tbody>
                    {% for doc in coverage.uncovered %}
                    <tr>
                        <td>
                            {% if doc.type.value == 'deprecation' %}
                                <span class="badge text-bg-warning">Deprecation</span>
                            {% elseif doc.type.value == 'breaking' %}
                                <span class="badge text-bg-danger">Breaking</span>
                            {% else %}
                                <span class="badge text-bg-secondary">{{ doc.type.value }}</span>
                            {% endif %}
                        </td>
                        <td><span class="badge text-bg-light text-dark border">{{ doc.version }}</span></td>
                        <td>
                            <a href="{{ path('deprecation_detail', {filename: doc.filename}) }}" class="text-decoration-none fw-medium">
                                {{ doc.title }}
                            </a>
                        </td>
                        <td>
                            {% if doc.scanStatus.value == 'fully_scanned' %}
                                <span class="badge text-bg-success">Fully Scanned</span>
                            {% elseif doc.scanStatus.value == 'partially_scanned' %}
                                <span class="badge text-bg-warning">Partially</span>
                            {% else %}
                                <span class="badge text-bg-secondary">Not Scanned</span>
                            {% endif %}
                        </td>
                        <td class="text-center">
                            {% if doc.codeReferences|length > 0 %}
                                <span class="badge rounded-pill text-bg-primary">{{ doc.codeReferences|length }}</span>
                            {% else %}
                                <span class="text-muted">&mdash;</span>
                            {% endif %}
                        </td>
                    </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    </div>
</div>
```

**Step 2: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 3: Commit**

```bash
git add templates/coverage/index.html.twig
git commit -m "Aufklappbare Tabelle nicht abgedeckter Dokumente im Coverage-Report"
```

---

### Task 6: Dashboard Coverage-Karten mit Link zum Report

**Files:**
- Modify: `templates/dashboard/index.html.twig`

**Step 1: Add link to Coverage-Report**

Im Dashboard-Template die Coverage-bezogenen Elemente mit Links zum Coverage-Report versehen. Den Coverage-Fortschrittsbalken-Bereich (die Card mit "Matcher-Coverage" Titel) um einen Link erweitern:

Suche die Card mit dem Fortschrittsbalken und ersetze den `<h6>` Tag:

```twig
<h6 class="card-title mb-0">
    <a href="{{ path('coverage_report') }}" class="text-decoration-none">Matcher-Coverage</a>
</h6>
```

Ebenso die Stat-Card "Matcher-Coverage" und "Ohne Matcher" anklickbar machen — die `<h5 class="card-title">` jeweils in einen Link zum Coverage-Report wrappen.

**Step 2: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 3: Commit**

```bash
git add templates/dashboard/index.html.twig
git commit -m "Dashboard Coverage-Elemente mit Link zum Coverage-Report versehen"
```

---

### Task 7: Manuell testen und Roadmap aktualisieren

**Files:**
- Modify: `CLAUDE.md`

**Step 1: Manueller Smoke-Test**

Im Browser prüfen:
1. `/coverage` — Seite lädt, alle vier Breakdown-Karten sichtbar
2. Fortschrittsbalken korrekt eingefärbt (grün/gelb/rot)
3. Uncovered-Tabelle aufklappbar
4. Sidebar-Link hervorgehoben wenn auf `/coverage`
5. Dashboard-Links führen zum Coverage-Report

**Step 2: Roadmap aktualisieren**

In `CLAUDE.md` den Eintrag "Coverage-Report mit prozentualer Aufschlüsselung" als erledigt markieren oder in einen "Done"-Bereich verschieben. Den Eintrag aus der v1.1-Roadmap entfernen, da Feature implementiert.

**Step 3: Run CI**

Run: `docker exec typo3-migration-analyzer-phpfpm-1 composer ci:test`
Expected: 0 errors, all tests pass

**Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "Coverage-Report als implementiert in der Roadmap markieren"
```

---

## Zusammenfassung

| Task | Was | Neue Dateien | Geänderte Dateien |
|------|-----|-------------|-------------------|
| 1 | byScanStatus Breakdown | — | CoverageResult, Analyzer, Test |
| 2 | byMatcherType Breakdown | — | CoverageResult, Analyzer, Test |
| 3 | Controller + Template | CoverageController, coverage/index.html.twig | — |
| 4 | Sidebar-Navigation | — | base.html.twig |
| 5 | Uncovered-Tabelle | — | coverage/index.html.twig |
| 6 | Dashboard-Links | — | dashboard/index.html.twig |
| 7 | Smoke-Test + Roadmap | — | CLAUDE.md |
