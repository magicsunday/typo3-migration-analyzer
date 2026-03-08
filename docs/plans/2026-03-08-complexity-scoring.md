# Complexity Scoring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Score each RST document 1-5 for migration complexity so users can prioritize easy wins and identify manual-effort items.

**Architecture:** New `ComplexityScorer` analyzes each `RstDocument` using its migration text, code references, index tags, and migration mappings to determine a complexity score. The scorer uses a rule-based approach: check for known patterns (hook→event, TCA, rename with 1:1 mapping, argument changes, no clear replacement) and assign scores. The `DeprecationController` computes scores on-the-fly via autowired `ComplexityScorer`. The list template gets a new Complexity column with colored badges and a filter dropdown for "only automatable".

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5.3

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- One class per file, `final readonly` for DTOs, `use function` imports
- English PHPDoc + English inline comments

---

### Task 1: ComplexityScore DTO

**Files:**
- Create: `src/Dto/ComplexityScore.php`
- Test: `tests/Unit/Dto/ComplexityScoreTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Dto/ComplexityScoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ComplexityScore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplexityScore::class)]
final class ComplexityScoreTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $score = new ComplexityScore(
            score: 3,
            reason: 'Argument signature changed',
            automatable: false,
        );

        self::assertSame(3, $score->score);
        self::assertSame('Argument signature changed', $score->reason);
        self::assertFalse($score->automatable);
    }

    #[Test]
    public function trivialScoreIsAutomatable(): void
    {
        $score = new ComplexityScore(
            score: 1,
            reason: 'Class renamed with 1:1 mapping',
            automatable: true,
        );

        self::assertSame(1, $score->score);
        self::assertTrue($score->automatable);
    }

    #[Test]
    public function manualScoreIsNotAutomatable(): void
    {
        $score = new ComplexityScore(
            score: 5,
            reason: 'Architecture change without clear replacement',
            automatable: false,
        );

        self::assertSame(5, $score->score);
        self::assertFalse($score->automatable);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `docker compose exec -T phpfpm phpunit tests/Unit/Dto/ComplexityScoreTest.php`
Expected: FAIL — class does not exist.

**Step 3: Write the implementation**

Create `src/Dto/ComplexityScore.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents the complexity assessment of a migration document.
 *
 * Score scale:
 *   1 = trivial (class/method renamed, 1:1 mapping)
 *   2 = easy (method removed with clear replacement)
 *   3 = medium (argument signature changed)
 *   4 = complex (hook→event migration, TCA restructure)
 *   5 = manual (architecture change without clear replacement)
 */
final readonly class ComplexityScore
{
    public function __construct(
        public int $score,
        public string $reason,
        public bool $automatable,
    ) {
    }
}
```

**Step 4: Run tests**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 5: Commit**

```
Add ComplexityScore DTO for migration complexity assessment
```

---

### Task 2: ComplexityScorer — core scoring logic

**Files:**
- Create: `src/Analyzer/ComplexityScorer.php`
- Test: `tests/Unit/Analyzer/ComplexityScorerTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Analyzer/ComplexityScorerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\ComplexityScorer;
use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeBlock;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplexityScorer::class)]
final class ComplexityScorerTest extends TestCase
{
    private ComplexityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ComplexityScorer(new MigrationMappingExtractor());
    }

    #[Test]
    public function scoreClassRenamedWithMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreMethodRenamedWithMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\Foo::oldMethod()` with :php:`\TYPO3\CMS\Core\Foo::newMethod()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'oldMethod', CodeReferenceType::StaticMethod),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreMethodRemovedWithClearReplacement(): void
    {
        $doc = $this->createDocument(
            migration: 'Use :php:`\TYPO3\CMS\Core\NewClass` instead of :php:`\TYPO3\CMS\Core\OldClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', 'doSomething', CodeReferenceType::InstanceMethod),
                new CodeReference('TYPO3\CMS\Core\OldClass', 'doOther', CodeReferenceType::InstanceMethod),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(2, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreArgumentSignatureChange(): void
    {
        $doc = $this->createDocument(
            migration: 'The method signature has changed. Adapt your code accordingly.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'bar', CodeReferenceType::InstanceMethod),
            ],
            codeBlocks: [
                new CodeBlock('php', 'public function bar(string $a, int $b, bool $c = false): void {}', 'After'),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(3, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreHookToEventMigration(): void
    {
        $doc = $this->createDocument(
            title: 'Removed hook for overriding icon overlay identifier',
            migration: 'Use the PSR-14 event :php:`\TYPO3\CMS\Core\Imaging\Event\IconOverlayEvent` instead.',
            indexTags: ['ext:core', 'Hook'],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(4, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreTcaRestructure(): void
    {
        $doc = $this->createDocument(
            title: 'TCA type bitmask removed',
            migration: 'Migrate your TCA configuration to use the new type.',
            indexTags: ['TCA'],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(4, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreArchitectureChangeWithoutReplacement(): void
    {
        $doc = $this->createDocument(
            migration: 'The entire subsystem has been redesigned. Review your architecture.',
            codeReferences: [],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(5, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreDocumentWithNoMigrationText(): void
    {
        $doc = $this->createDocument(
            migration: null,
            codeReferences: [],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(5, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scorePropertyChangeWithMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'The property :php:`\TYPO3\CMS\Core\Foo::$old` has been renamed to :php:`\TYPO3\CMS\Core\Foo::$new`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'old', CodeReferenceType::Property),
            ],
        );

        $result = $this->scorer->score($doc);

        // Property changes with mappings are simple renames
        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    /**
     * @param list<CodeReference> $codeReferences
     * @param list<CodeBlock>     $codeBlocks
     * @param list<string>        $indexTags
     */
    private function createDocument(
        ?string $migration = '',
        array $codeReferences = [],
        array $codeBlocks = [],
        string $title = 'Test document',
        array $indexTags = [],
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 0,
            title: $title,
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: $migration,
            codeReferences: $codeReferences,
            indexTags: $indexTags,
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-99999-Test.rst',
            codeBlocks: $codeBlocks,
        );
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm phpunit tests/Unit/Analyzer/ComplexityScorerTest.php`
Expected: FAIL — class does not exist.

**Step 3: Write the implementation**

Create `src/Analyzer/ComplexityScorer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CodeReferenceType;
use App\Dto\ComplexityScore;
use App\Dto\RstDocument;

use function array_any;
use function count;
use function mb_strtolower;
use function str_contains;

/**
 * Scores the complexity of migrating a deprecated/breaking RST document.
 *
 * Uses a rule-based approach analyzing migration text, code references,
 * index tags, and migration mappings to assign a score from 1 (trivial) to 5 (manual).
 */
final readonly class ComplexityScorer
{
    public function __construct(
        private MigrationMappingExtractor $extractor,
    ) {
    }

    /**
     * Score the migration complexity of a document.
     */
    public function score(RstDocument $document): ComplexityScore
    {
        // Rule 1: Hook → Event migration (score 4)
        if ($this->isHookToEventMigration($document)) {
            return new ComplexityScore(4, 'Hook to event migration', false);
        }

        // Rule 2: TCA restructure (score 4)
        if ($this->isTcaChange($document)) {
            return new ComplexityScore(4, 'TCA structure change', false);
        }

        $mappings = $this->extractor->extract($document->migration);

        // Rule 3: 1:1 rename mapping exists (score 1)
        if ($mappings !== [] && $this->allReferencesHaveMappings($document, $mappings)) {
            return new ComplexityScore(1, 'Renamed with 1:1 mapping', true);
        }

        // Rule 4: Partial mappings — some refs have replacement (score 2)
        if ($mappings !== []) {
            return new ComplexityScore(2, 'Partial replacement available', true);
        }

        // Rule 5: Method/function refs with code blocks suggest argument changes (score 3)
        if ($this->hasMethodRefsWithCodeBlocks($document)) {
            return new ComplexityScore(3, 'Argument signature changed', false);
        }

        // Rule 6: Has code references but no mappings (score 3)
        if ($document->codeReferences !== []) {
            return new ComplexityScore(3, 'Code references without mapping', false);
        }

        // Rule 7: No migration text or no code references at all (score 5)
        return new ComplexityScore(5, 'Architecture change without clear replacement', false);
    }

    private function isHookToEventMigration(RstDocument $document): bool
    {
        $hasHookTag = array_any(
            $document->indexTags,
            static fn (string $tag): bool => mb_strtolower($tag) === 'hook',
        );

        if ($hasHookTag) {
            return true;
        }

        $title = mb_strtolower($document->title);

        return str_contains($title, 'hook') && str_contains($title, 'removed');
    }

    private function isTcaChange(RstDocument $document): bool
    {
        $hasTcaTag = array_any(
            $document->indexTags,
            static fn (string $tag): bool => mb_strtolower($tag) === 'tca',
        );

        if ($hasTcaTag) {
            return true;
        }

        return str_contains(mb_strtolower($document->title), 'tca');
    }

    /**
     * Check whether all code references in the document have corresponding mappings.
     *
     * @param list<\App\Dto\MigrationMapping> $mappings
     */
    private function allReferencesHaveMappings(RstDocument $document, array $mappings): bool
    {
        if ($document->codeReferences === []) {
            // No code references but mappings exist — treat as simple rename
            return true;
        }

        $mappedSources = [];

        foreach ($mappings as $mapping) {
            $key = $mapping->source->className . '::' . ($mapping->source->member ?? '');
            $mappedSources[$key] = true;
        }

        foreach ($document->codeReferences as $ref) {
            $key = $ref->className . '::' . ($ref->member ?? '');

            if (!isset($mappedSources[$key])) {
                return false;
            }
        }

        return true;
    }

    private function hasMethodRefsWithCodeBlocks(RstDocument $document): bool
    {
        if ($document->codeBlocks === []) {
            return false;
        }

        return array_any(
            $document->codeReferences,
            static fn ($ref): bool => $ref->type === CodeReferenceType::InstanceMethod
                || $ref->type === CodeReferenceType::StaticMethod,
        );
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
Add ComplexityScorer for rule-based migration complexity assessment
```

---

### Task 3: Integrate scoring into DeprecationController

**Files:**
- Modify: `src/Controller/DeprecationController.php`
- Modify: `templates/deprecation/detail.html.twig`

**Step 1: Modify detail() action**

Add `ComplexityScorer` as an autowired parameter to the `detail()` action. Compute the score and pass it to the template.

In `src/Controller/DeprecationController.php`:

```php
use App\Analyzer\ComplexityScorer;

// In detail() method — add $complexityScorer parameter:
public function detail(
    string $filename,
    DocumentService $documentService,
    MigrationMappingExtractor $extractor,
    ComplexityScorer $complexityScorer,
): Response {
    $doc = $documentService->findDocumentByFilename($filename);

    if (!$doc instanceof RstDocument) {
        throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
    }

    return $this->render('deprecation/detail.html.twig', [
        'doc'        => $doc,
        'mappings'   => $extractor->extract($doc->migration),
        'complexity' => $complexityScorer->score($doc),
    ]);
}
```

**Step 2: Add complexity badge to detail template**

In `templates/deprecation/detail.html.twig`, add a complexity badge in the badge row (around line 28, after the scan status badge):

```twig
{% if complexity is defined %}
    {% if complexity.score <= 2 %}
        <span class="badge text-bg-success" title="{{ complexity.reason }}">Complexity {{ complexity.score }}/5</span>
    {% elseif complexity.score == 3 %}
        <span class="badge text-bg-warning" title="{{ complexity.reason }}">Complexity {{ complexity.score }}/5</span>
    {% else %}
        <span class="badge text-bg-danger" title="{{ complexity.reason }}">Complexity {{ complexity.score }}/5</span>
    {% endif %}
    {% if complexity.automatable %}
        <span class="badge text-bg-info">Automatable</span>
    {% endif %}
{% endif %}
```

**Step 3: Run tests and commit**

Run: `docker compose exec -T phpfpm composer ci:test`

```
Add complexity score to deprecation detail page
```

---

### Task 4: Add scoring to list page with filter

**Files:**
- Modify: `src/Controller/DeprecationController.php` (list action)
- Modify: `templates/deprecation/list.html.twig`

**Step 1: Modify list() action**

In `src/Controller/DeprecationController.php`, add `ComplexityScorer` to `list()` action. Compute scores for all documents and pass as a map keyed by filename. Add filter for "automatable" and "complexity".

```php
// In list() method — add ComplexityScorer parameter:
public function list(
    Request $request,
    DocumentService $documentService,
    ComplexityScorer $complexityScorer,
): Response {
    // ... existing filter logic ...

    // Add complexity filter
    $filters['complexity'] = $request->query->getString('complexity');
    $filters['automatable'] = $request->query->getString('automatable');

    // Compute scores for all documents
    $scores = [];
    foreach ($documents as $doc) {
        $scores[$doc->filename] = $complexityScorer->score($doc);
    }

    // Apply complexity filter
    if ($filters['complexity'] !== '') {
        $filterComplexity = (int) $filters['complexity'];
        $documents = array_filter(
            $documents,
            static fn (RstDocument $doc) => $scores[$doc->filename]->score === $filterComplexity,
        );
    }

    // Apply automatable filter
    if ($filters['automatable'] === '1') {
        $documents = array_filter(
            $documents,
            static fn (RstDocument $doc) => $scores[$doc->filename]->automatable,
        );
    }

    $documents = array_values($documents);

    return $this->render('deprecation/list.html.twig', [
        'documents' => $documents,
        'versions'  => $documentService->getVersions(),
        'filters'   => $filters,
        'scores'    => $scores,
    ]);
}
```

**Step 2: Add complexity column and filters to list template**

In `templates/deprecation/list.html.twig`:

a) Add filter dropdowns in the filter bar (after scan status filter, around line 60):

```twig
{# Complexity filter #}
<div class="col-auto">
    <select name="complexity" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Complexity</option>
        {% for level in 1..5 %}
            <option value="{{ level }}" {{ filters.complexity == level ? 'selected' }}>
                Score {{ level }}
            </option>
        {% endfor %}
    </select>
</div>

{# Automatable filter #}
<div class="col-auto">
    <a href="{{ path('deprecation_list', filters|merge({automatable: filters.automatable == '1' ? '' : '1'})) }}"
       class="btn btn-sm {{ filters.automatable == '1' ? 'btn-info' : 'btn-outline-info' }}">
        <i class="bi bi-robot me-1"></i>Automatable
    </a>
</div>
```

b) Add Complexity column header in the table (after Scan Status th):

```twig
<th class="text-center">Complexity</th>
```

c) Add complexity badge in each row (after scan status td):

```twig
<td class="text-center">
    {% set score = scores[doc.filename] %}
    {% if score.score <= 2 %}
        <span class="badge text-bg-success" title="{{ score.reason }}">{{ score.score }}</span>
    {% elseif score.score == 3 %}
        <span class="badge text-bg-warning" title="{{ score.reason }}">{{ score.score }}</span>
    {% else %}
        <span class="badge text-bg-danger" title="{{ score.reason }}">{{ score.score }}</span>
    {% endif %}
    {% if score.automatable %}
        <i class="bi bi-robot text-info ms-1" title="Automatable"></i>
    {% endif %}
</td>
```

**Step 3: Run tests and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`

```
Add complexity scoring column and filters to deprecation list
```

---

### Task 5: Update CLAUDE.md roadmap

**Files:**
- Modify: `CLAUDE.md`

**Step 1: Mark complexity scoring as done in roadmap**

In `CLAUDE.md`, under v1.2, change the complexity scoring line:

```markdown
- [ ] Komplexitäts-Scoring pro Deprecation (Score 1-5, automatisierbar vs. manuell)
```

to:

```markdown
- [x] Komplexitäts-Scoring pro Deprecation (Score 1-5, automatisierbar vs. manuell)
```

**Step 2: Commit**

```
Mark complexity scoring as completed in roadmap
```

---

### Task 6: Code review and cleanup

**Step 1: Run full CI suite**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Apply any auto-fixes.

Run: `docker compose exec -T phpfpm composer ci:test`
Verify everything is green.

**Step 2: Code review**

Review all new and modified files for:
- Scoring logic correctness (are the rules in the right priority order?)
- Edge cases (documents with no migration text, no code references, etc.)
- Template consistency (badges match existing color scheme)
- Filter form works correctly (hidden inputs preserve other filters)
- No XSS risks (Twig auto-escapes)
- PHPDoc completeness
- `use function` imports

**Step 3: Fix any findings and commit**

```
Review findings: [describe fixes]
```

---

## Summary

| Task | Component | Files |
|------|-----------|-------|
| 1 | ComplexityScore DTO | `src/Dto/ComplexityScore.php`, test |
| 2 | ComplexityScorer (rule engine) | `src/Analyzer/ComplexityScorer.php`, test |
| 3 | Detail page integration | Controller + template |
| 4 | List page + filters | Controller + template |
| 5 | Roadmap update | `CLAUDE.md` |
| 6 | Code review + cleanup | All files |
