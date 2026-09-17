# Affected Installations + Matcher-Eligible Coverage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two verified defects that block this repo from being a
complete TYPO3 changelog source: `RstParser` silently drops the "Affected
installations" section, and `RstFileLocator` only ever discovers
Breaking/Deprecation files, never Feature/Important — while protecting the
existing matcher-coverage metric from being silently corrupted by the
second fix.

**Architecture:** No new architecture — this extends the existing
`RstDocument`/`RstParser`/`RstFileLocator`/`DocumentService` pipeline in
place. One new class, `MatcherEligibilityPolicy`, is introduced and wired
in *before* the document set is widened, so coverage numbers never pass
through a broken intermediate state.

**Tech Stack:** PHP 8.4, Symfony 7.4 (DI autowiring via
`config/services.yaml`'s `App\:` resource), PHPUnit with
`#[Test]`/`#[CoversClass]` attributes, run inside the `phpfpm` Docker
service (no PHP/Composer on the host — verified: `composer install` on
this host fails with `env: 'php': No such file or directory`).

**Spec:** `/srv/projects/typo3-extension-upgrade-skill/docs/specs/2026-09-17-typo3-changelog-knowledge-base-architecture.md`
(§2.3 "Two verified defects", §5.3 "matcher-eligible" policy, §9 step 1).
This plan implements the two blocking defect fixes from that spec's §9
step 1 — the *first* sub-item there ("Fix defect 1", "Fix defect 2" +
matcher-eligibility) — **not** the snapshot-schema/export/CLI work also
listed under step 1, which is large enough to need its own separate plan
(see "Deliberately out of scope" below). Executors should read the spec's
§2.3 and §5.3 before starting; this plan does not repeat their reasoning,
only their conclusions.

## Global Constraints

From this repo's own `CLAUDE.md`, apply to every task below:
- PHP 8.4+, actively use PHP 8.4 language features.
- No `mixed` types, no `empty()`.
- Classes `final`, `readonly` where all properties are readonly.
- No `@deprecated` annotations — if something is superseded, remove it.
- Every new/changed class gets tests; every concrete test class carries
  `#[CoversClass]`.
- English PHPDoc on classes/methods (description + params); English inline
  comments only where the code is non-obvious.
- Meaningful, non-abbreviated identifiers.
- No nested ternaries; explicit type declarations on class constants.
- `use function`-import global function calls (already the convention in
  every file this plan touches).
- Before every commit: `composer ci:cgl` and `composer ci:rector`
  (auto-fix), then `composer ci:test` must be green — all four run inside
  the `phpfpm` container (see "Environment" below), never on the bare
  host.
- Commit subjects: capitalised English imperative (`^[A-Z]`), no
  `Co-Authored-By` trailer, no AI attribution. No GitHub issue exists for
  this work yet, so no `GH-<N>:` prefix is required — if the user wants
  one filed first, that's a decision for them, not this plan.
- German umlauts correct where German text appears (none in this plan's
  scope — all touched code/comments are English).

**Environment (read before Task 1):**
```bash
# One-time, from the repo root:
COMPOSE_FILE=compose.yaml:compose.development.yaml docker compose up -d
docker compose exec phpfpm composer install

# Every test run in this plan:
docker compose exec phpfpm composer ci:test:php:unit
# Full pre-commit gate:
docker compose exec phpfpm composer ci:cgl
docker compose exec phpfpm composer ci:rector
docker compose exec phpfpm composer ci:test
```

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `src/Dto/RstDocument.php` | modify | add `?string $affectedInstallations = null` |
| `src/Parser/RstParser.php` | modify | extract the "Affected installations" RST section |
| `tests/Unit/Dto/RstDocumentTest.php` | modify | DTO accepts/defaults the new field |
| `tests/Unit/Parser/RstParserTest.php` | modify | parser extracts it from real fixtures |
| `src/Analyzer/MatcherEligibilityPolicy.php` | create | schema-v1 matcher-eligible type filter (Breaking + Deprecation) |
| `tests/Unit/Analyzer/MatcherEligibilityPolicyTest.php` | create | policy tested in isolation |
| `src/Service/DocumentService.php` | modify | `getCoverage()` filters through the policy before analysis |
| `tests/Unit/Service/DocumentServiceTest.php` | modify | constructor updated; new coverage-subset assertion |
| `src/Parser/RstFileLocator.php` | modify | widen discovery regex to include Feature/Important (defect 2) |
| `tests/Integration/Parser/RstFileLocatorTest.php` | modify | Feature/Important discovered from real vendor data |

**Deliberately out of scope for this plan** (per the spec's §4 non-goals
and this plan's own scope cut): the snapshot-schema mapping layer
(`SnapshotEntryV1`, JSON Schema files), `ATTRIBUTION.md`/license
groundwork, the parse-failure-fails-the-build rule, the source-file-path
primary key, and the `changelog:export`/`report:coverage`/`snapshot:build`
CLI commands — all still listed under the spec's §9 step 1/2, but large
and design-heavy enough to warrant their own follow-up plan once this one
lands. Also out of scope: anything in `typo3-extension-upgrade` itself
(spec §9 steps 3-6) — that plan is written separately, after this one's
snapshot-relevant groundwork is further along.

---

### Task 1: Parse "Affected installations" into `RstDocument`

**Files:**
- Modify: `src/Dto/RstDocument.php`
- Modify: `src/Parser/RstParser.php`
- Test: `tests/Unit/Dto/RstDocumentTest.php`
- Test: `tests/Unit/Parser/RstParserTest.php`

**Interfaces:**
- Produces: `RstDocument::$affectedInstallations` (`?string`, defaults to
  `null` when the RST document has no such section, or when constructed
  without naming it — matches the existing `$impact`/`$migration`
  nullable-section convention).

- [ ] **Step 1: Write the failing DTO test**

Add to `tests/Unit/Dto/RstDocumentTest.php` (inside the existing
`final class RstDocumentTest extends TestCase` body, after
`codeBlocksDefaultsToEmptyArray`):

```php
    #[Test]
    public function constructionWithAffectedInstallations(): void
    {
        $document = new RstDocument(
            type: DocumentType::Breaking,
            issueId: 88888,
            title: 'Test class has been removed',
            version: '12.0',
            description: 'The class has been removed.',
            impact: 'Using the removed class will cause a fatal error.',
            migration: 'Use the new class instead.',
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Breaking-88888-TestBreaking.rst',
            affectedInstallations: 'Extensions using the removed class.',
        );

        self::assertSame('Extensions using the removed class.', $document->affectedInstallations);
    }

    #[Test]
    public function affectedInstallationsDefaultsToNull(): void
    {
        $document = new RstDocument(
            type: DocumentType::Breaking,
            issueId: 12345,
            title: 'Test',
            version: '12.0',
            description: 'Test description.',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Breaking-12345-Test.rst',
        );

        self::assertNull($document->affectedInstallations);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --configuration phpunit.xml --filter RstDocumentTest`
Expected: FAIL — `Unknown named parameter $affectedInstallations` (the
constructor doesn't accept it yet).

- [ ] **Step 3: Add the field to `RstDocument`**

In `src/Dto/RstDocument.php`, change the constructor from:

```php
    public function __construct(
        public DocumentType $type,
        public int $issueId,
        public string $title,
        public string $version,
        public string $description,
        public ?string $impact,
        public ?string $migration,
        public array $codeReferences,
        public array $indexTags,
        public ScanStatus $scanStatus,
        public string $filename,
        public array $codeBlocks = [],
    ) {
    }
```

to:

```php
    public function __construct(
        public DocumentType $type,
        public int $issueId,
        public string $title,
        public string $version,
        public string $description,
        public ?string $impact,
        public ?string $migration,
        public array $codeReferences,
        public array $indexTags,
        public ScanStatus $scanStatus,
        public string $filename,
        public array $codeBlocks = [],
        public ?string $affectedInstallations = null,
    ) {
    }
```

- [ ] **Step 4: Run to verify the DTO test passes**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --configuration phpunit.xml --filter RstDocumentTest`
Expected: PASS (all `RstDocumentTest` methods, including the two new
ones).

- [ ] **Step 5: Write the failing parser test**

Add to `tests/Unit/Parser/RstParserTest.php` (after `parseBreakingDocument`):

```php
    #[Test]
    public function extractAffectedInstallationsFromBreakingDocument(): void
    {
        $filePath = $this->fixturesDir . '/Breaking-88888-TestBreaking.rst';

        $document = $this->parser->parseFile($filePath, '12.0');

        self::assertNotNull($document->affectedInstallations);
        self::assertStringContainsString('Extensions using the removed class', $document->affectedInstallations);
    }

    #[Test]
    public function extractAffectedInstallationsFromDeprecationDocument(): void
    {
        $filePath = $this->fixturesDir . '/Deprecation-99999-TestDeprecation.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        self::assertNotNull($document->affectedInstallations);
        self::assertStringContainsString('All installations using the deprecated method', $document->affectedInstallations);
    }
```

(Both fixtures already contain an "Affected installations" section —
verified: `tests/Fixtures/Rst/Breaking-88888-TestBreaking.rst` and
`Deprecation-99999-TestDeprecation.rst` each have one. No fixture changes
needed.)

- [ ] **Step 6: Run to verify it fails**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --configuration phpunit.xml --filter RstParserTest`
Expected: FAIL on both new tests — `affectedInstallations` is `null`
(the field exists now, but nothing sets it).

- [ ] **Step 7: Wire the extraction in `RstParser`**

In `src/Parser/RstParser.php`, `parseFile()` currently reads:

```php
        $filename  = basename($filePath);
        $migration = $this->extractSection($content, 'Migration');

        return new RstDocument(
            type: $this->extractType($filename),
            issueId: $this->extractIssueId($content),
            title: $this->extractTitle($content),
            version: $version,
            description: $this->extractSection($content, 'Description') ?? '',
            impact: $this->extractSection($content, 'Impact'),
            migration: $migration,
            codeReferences: $this->extractCodeReferences($content),
            indexTags: $this->extractIndexTags($content),
            scanStatus: $this->extractScanStatus($content),
            filename: $filename,
            codeBlocks: $this->codeBlockExtractor->extract($migration),
        );
```

Change to:

```php
        $filename  = basename($filePath);
        $migration = $this->extractSection($content, 'Migration');

        return new RstDocument(
            type: $this->extractType($filename),
            issueId: $this->extractIssueId($content),
            title: $this->extractTitle($content),
            version: $version,
            description: $this->extractSection($content, 'Description') ?? '',
            impact: $this->extractSection($content, 'Impact'),
            migration: $migration,
            codeReferences: $this->extractCodeReferences($content),
            indexTags: $this->extractIndexTags($content),
            scanStatus: $this->extractScanStatus($content),
            filename: $filename,
            codeBlocks: $this->codeBlockExtractor->extract($migration),
            affectedInstallations: $this->extractSection($content, 'Affected installations'),
        );
```

(`extractSection()` already exists and is generic — no change to that
method itself; it is called once more with a new section name.)

- [ ] **Step 8: Run to verify it passes**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --configuration phpunit.xml --filter RstParserTest`
Expected: PASS (all `RstParserTest` methods, including the two new ones).

- [ ] **Step 9: Run the full PHPUnit suite for regressions**

Run: `docker compose exec phpfpm composer ci:test:php:unit`
Expected: PASS, no regressions in any other test (every other DTO/parser
construction call in the suite either doesn't reference the new field or
relies on its `null` default, both of which stay valid). Despite the
script's name, `phpunit.xml` runs both the `Unit` and `Integration`
testsuites here — there is no `--testsuite` filter in `ci:test:php:unit`.

- [ ] **Step 10: Auto-fix, run the full CI gate, then commit**

```bash
docker compose exec phpfpm composer ci:cgl
docker compose exec phpfpm composer ci:rector
docker compose exec phpfpm composer ci:test

git add src/Dto/RstDocument.php src/Parser/RstParser.php \
        tests/Unit/Dto/RstDocumentTest.php tests/Unit/Parser/RstParserTest.php
git commit -m "Parse Affected installations section into RstDocument"
```

`ci:cgl`/`ci:rector` run first because they can still rewrite the code
after Step 9's test run; `ci:test` (which re-checks style/rector in
`--dry-run` mode alongside phpstan/lint/cpd) must therefore run *after*
them, immediately before the commit — never the other way around.

---

### Task 2: Add `MatcherEligibilityPolicy`, tested in isolation

**Files:**
- Create: `src/Analyzer/MatcherEligibilityPolicy.php`
- Test: `tests/Unit/Analyzer/MatcherEligibilityPolicyTest.php`

**Interfaces:**
- Consumes: `RstDocument::$type` (`DocumentType`, existing).
- Produces: `MatcherEligibilityPolicy::filter(array $documents): array`
  (`RstDocument[] → RstDocument[]`) — consumed by Task 3, not wired into
  `DocumentService` yet in this task (kept standalone and independently
  testable first, on purpose: wiring it happens in Task 3 alongside the
  document-set widening, so the two land together as the spec requires,
  but this task proves the filtering logic correct on its own first).

**Note for the follow-up snapshot-schema plan, not this one**: the spec's
`coverage.json` needs an `eligibilityPolicy: {version, documentTypes}`
block so the coverage percentage's meaning is machine-verifiable, not just
documented in prose. Rather than the snapshot builder hard-coding
`["breaking", "deprecation"]` a second time, that follow-up plan should
have `MatcherEligibilityPolicy` expose its own type list and a version
constant (e.g. a `eligibleTypes(): array` accessor next to `filter()`).
Not added here — YAGNI, this plan has no caller for it yet — but the class
name and shape below are deliberately left easy to extend that way.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Analyzer/MatcherEligibilityPolicyTest.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\MatcherEligibilityPolicy;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatcherEligibilityPolicy::class)]
final class MatcherEligibilityPolicyTest extends TestCase
{
    private MatcherEligibilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new MatcherEligibilityPolicy();
    }

    #[Test]
    public function filterKeepsBreakingAndDeprecationOnly(): void
    {
        $documents = [
            $this->createDocument(DocumentType::Breaking, 'Breaking-1-A.rst'),
            $this->createDocument(DocumentType::Deprecation, 'Deprecation-2-B.rst'),
            $this->createDocument(DocumentType::Feature, 'Feature-3-C.rst'),
            $this->createDocument(DocumentType::Important, 'Important-4-D.rst'),
        ];

        $eligible = $this->policy->filter($documents);

        self::assertCount(2, $eligible);

        $types = array_map(
            static fn (RstDocument $document): DocumentType => $document->type,
            $eligible,
        );

        self::assertContains(DocumentType::Breaking, $types);
        self::assertContains(DocumentType::Deprecation, $types);
        self::assertNotContains(DocumentType::Feature, $types);
        self::assertNotContains(DocumentType::Important, $types);
    }

    #[Test]
    public function filterOfEmptyListReturnsEmptyList(): void
    {
        self::assertSame([], $this->policy->filter([]));
    }

    private function createDocument(DocumentType $type, string $filename): RstDocument
    {
        return new RstDocument(
            type: $type,
            issueId: 1,
            title: 'Test',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --configuration phpunit.xml --filter MatcherEligibilityPolicyTest`
Expected: FAIL — `Class "App\Analyzer\MatcherEligibilityPolicy" not found`.

- [ ] **Step 3: Implement `MatcherEligibilityPolicy`**

Create `src/Analyzer/MatcherEligibilityPolicy.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\DocumentType;
use App\Dto\RstDocument;

use function array_filter;
use function array_values;
use function in_array;

/**
 * Defines which changelog document types are eligible for TYPO3 Extension
 * Scanner matcher coverage.
 *
 * Snapshot schema v1 policy: Breaking and Deprecation only. Feature and
 * Important entries essentially never carry a code-level matcher by
 * nature (new configuration, a recommended API, a changed default), so
 * including them in the coverage denominator would misrepresent what the
 * percentage means. Widening this set is a schema v2 policy change, not
 * a silent v1 reinterpretation.
 */
final readonly class MatcherEligibilityPolicy
{
    /**
     * @var list<DocumentType>
     */
    private const array ELIGIBLE_TYPES = [
        DocumentType::Breaking,
        DocumentType::Deprecation,
    ];

    /**
     * @param RstDocument[] $documents
     *
     * @return RstDocument[]
     */
    public function filter(array $documents): array
    {
        return array_values(
            array_filter(
                $documents,
                static fn (RstDocument $document): bool => in_array(
                    $document->type,
                    self::ELIGIBLE_TYPES,
                    true,
                ),
            ),
        );
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --configuration phpunit.xml --filter MatcherEligibilityPolicyTest`
Expected: PASS.

- [ ] **Step 5: Auto-fix, run the full CI gate, then commit**

```bash
docker compose exec phpfpm composer ci:cgl
docker compose exec phpfpm composer ci:rector
docker compose exec phpfpm composer ci:test

git add src/Analyzer/MatcherEligibilityPolicy.php tests/Unit/Analyzer/MatcherEligibilityPolicyTest.php
git commit -m "Add MatcherEligibilityPolicy for schema-v1 coverage scope"
```

---

### Task 3: Wire the policy into coverage, then widen discovery to all four types

**This is the atomic pair the spec requires** ("Introduce the
matcher-eligibility filter in the same change as defect 2, not as a
follow-up"), split into two commits for safety rather than one task-ending
commit: the first commit wires `MatcherEligibilityPolicy` into
`DocumentService::getCoverage()` while the document set is still
Breaking+Deprecation only (a behaviorally inert change — coverage numbers
do not move, because every document today is already eligible), and only
the second commit widens `RstFileLocator`. At no point between the two
commits is the coverage metric wrong.

Both commits are test-driven, but not in the same sense: the first commit
cannot yet produce a red *behavioral* test for the filter (there is
nothing ineligible in the document set for it to exclude at that point),
so its test coverage is compile-correctness plus "the existing
coverage-percent assertions stay green" — a deliberately inert change, not
an untested one. The actual behavioral proof that
`MatcherEligibilityPolicy` is wired in and doing something — that
`CoverageResult::totalDocuments` excludes Feature/Important — only becomes
possible, and is asserted red-then-green, once Step 5-8 widen discovery
below.

**Files:**
- Modify: `src/Service/DocumentService.php`
- Modify: `tests/Unit/Service/DocumentServiceTest.php`
- Modify: `tests/Unit/EventSubscriber/VersionRangeSubscriberTest.php` — a
  **second** real call site that constructs `DocumentService` positionally
  (verified: `grep -rn "new DocumentService" --include="*.php" .` finds
  exactly these two production-code call sites, both under `tests/`;
  every other hit is inside `docs/plans/*.md`). Missing this one leaves
  the constructor call one argument short, so the full suite would fail to
  even load after Step 2 below, not just show a wrong assertion.
- Modify: `src/Parser/RstFileLocator.php`
- Modify: `tests/Integration/Parser/RstFileLocatorTest.php`

**Interfaces:**
- Consumes: `MatcherEligibilityPolicy::filter()` (Task 2).
- Produces: `DocumentService::getDocuments()` returns all four
  `DocumentType`s once this task's second commit lands (unchanged method
  signature); `DocumentService::getCoverage()`'s `CoverageResult` reflects
  only the matcher-eligible subset throughout.

- [ ] **Step 1: Update both `DocumentService` test fixture helpers for the new constructor arg (will fail to compile until Step 2)**

In `tests/Unit/Service/DocumentServiceTest.php`, change:

```php
    private function createServiceWithFixtures(): DocumentService
    {
        return new DocumentService(
            new RstFileLocator(new RstParser()),
            new MatcherConfigParser(),
            new MatcherCoverageAnalyzer(),
            new VersionRangeProvider(),
            new ArrayAdapter(),
        );
    }
```

to:

```php
    private function createServiceWithFixtures(): DocumentService
    {
        return new DocumentService(
            new RstFileLocator(new RstParser()),
            new MatcherConfigParser(),
            new MatcherCoverageAnalyzer(),
            new VersionRangeProvider(),
            new ArrayAdapter(),
            new MatcherEligibilityPolicy(),
        );
    }
```

and add the import `use App\Analyzer\MatcherEligibilityPolicy;` alongside
the existing `use App\Analyzer\MatcherCoverageAnalyzer;` line.

Then, in `tests/Unit/EventSubscriber/VersionRangeSubscriberTest.php`,
apply the identical change to its own `createDocumentService()` helper.
Change:

```php
    private function createDocumentService(): DocumentService
    {
        return new DocumentService(
            new RstFileLocator(new RstParser()),
            new MatcherConfigParser(),
            new MatcherCoverageAnalyzer(),
            new VersionRangeProvider(),
            new ArrayAdapter(),
        );
    }
```

to:

```php
    private function createDocumentService(): DocumentService
    {
        return new DocumentService(
            new RstFileLocator(new RstParser()),
            new MatcherConfigParser(),
            new MatcherCoverageAnalyzer(),
            new VersionRangeProvider(),
            new ArrayAdapter(),
            new MatcherEligibilityPolicy(),
        );
    }
```

and add `use App\Analyzer\MatcherEligibilityPolicy;` to this file's use
block too (alongside its existing `use App\Analyzer\MatcherCoverageAnalyzer;`
at the top of the file).

- [ ] **Step 2: Wire the policy into `DocumentService`**

In `src/Service/DocumentService.php`, change the constructor from:

```php
    public function __construct(
        private readonly RstFileLocator $locator,
        private readonly MatcherConfigParser $matcherParser,
        private readonly MatcherCoverageAnalyzer $coverageAnalyzer,
        private readonly VersionRangeProvider $versionRangeProvider,
        private readonly CacheInterface $cache,
    ) {
        $this->versionRange = $this->versionRangeProvider->getDefaultRange();
    }
```

to:

```php
    public function __construct(
        private readonly RstFileLocator $locator,
        private readonly MatcherConfigParser $matcherParser,
        private readonly MatcherCoverageAnalyzer $coverageAnalyzer,
        private readonly VersionRangeProvider $versionRangeProvider,
        private readonly CacheInterface $cache,
        private readonly MatcherEligibilityPolicy $eligibilityPolicy,
    ) {
        $this->versionRange = $this->versionRangeProvider->getDefaultRange();
    }
```

(only the parameter list changes — the constructor body stays exactly as
it is, shown here in full so the diff is unambiguous) and add
`use App\Analyzer\MatcherEligibilityPolicy;` to the file's use block
(alongside the existing `use App\Analyzer\MatcherCoverageAnalyzer;`).

Then change `getCoverage()` from:

```php
    public function getCoverage(): CoverageResult
    {
        $cacheKey = sprintf('coverage_result_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): CoverageResult {
            $item->expiresAfter(3600);

            return $this->coverageAnalyzer->analyze($this->getDocuments(), $this->getMatchers());
        });
    }
```

to:

```php
    public function getCoverage(): CoverageResult
    {
        $cacheKey = sprintf('coverage_result_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): CoverageResult {
            $item->expiresAfter(3600);

            $eligibleDocuments = $this->eligibilityPolicy->filter($this->getDocuments());

            return $this->coverageAnalyzer->analyze($eligibleDocuments, $this->getMatchers());
        });
    }
```

(`config/services.yaml`'s `App\: resource: '../src/'` with
`autowire: true` picks up `MatcherEligibilityPolicy` automatically — no
manual service registration needed, matching every other service in this
class.)

- [ ] **Step 3: Run the full PHPUnit suite to confirm this commit is behaviorally inert**

Run: `docker compose exec phpfpm composer ci:test:php:unit`
Expected: PASS, and — this is the point of splitting the commit —
`DocumentServiceTest::getCoverageReturnsCorrectResult`'s coverage-percent
assertions are unaffected, because `RstFileLocator` still only discovers
Breaking/Deprecation at this point (Step 7 below changes that).

- [ ] **Step 4: Auto-fix, run the full CI gate, then commit the (inert) wiring**

```bash
docker compose exec phpfpm composer ci:cgl
docker compose exec phpfpm composer ci:rector
docker compose exec phpfpm composer ci:test

git add src/Service/DocumentService.php tests/Unit/Service/DocumentServiceTest.php \
        tests/Unit/EventSubscriber/VersionRangeSubscriberTest.php
git commit -m "Filter coverage analysis through MatcherEligibilityPolicy"
```

- [ ] **Step 5: Write the failing tests for full four-type discovery**

Add to `tests/Integration/Parser/RstFileLocatorTest.php` (after
`filterByType`):

```php
    #[Test]
    public function discoverFeatureAndImportantDocuments(): void
    {
        $provider  = new VersionRangeProvider();
        $range     = $provider->getDefaultRange();
        $versions  = $range->getVersionDirectories($provider->getAvailableDirectories());
        $documents = $this->locator->findAll($versions);

        $types = array_unique(
            array_map(
                static fn (RstDocument $doc): string => $doc->type->value,
                $documents,
            ),
        );

        self::assertContains(DocumentType::Feature->value, $types);
        self::assertContains(DocumentType::Important->value, $types);
    }
```

Add to `tests/Unit/Service/DocumentServiceTest.php` (after
`getCoverageReturnsCorrectResult`; add
`use App\Dto\DocumentType;` and `use App\Dto\RstDocument;` and
`use function array_filter;` and `use function count;` and
`use function in_array;` to the file's imports if not already present):

```php
    #[Test]
    public function getCoverageOnlyCountsMatcherEligibleDocuments(): void
    {
        $service = $this->createServiceWithFixtures();

        $allDocuments = $service->getDocuments();
        $coverage     = $service->getCoverage();

        $ineligibleCount = count(array_filter(
            $allDocuments,
            static fn (RstDocument $document): bool => !in_array(
                $document->type,
                [DocumentType::Breaking, DocumentType::Deprecation],
                true,
            ),
        ));

        self::assertGreaterThan(
            0,
            $ineligibleCount,
            'Expected Feature/Important documents in the default range — '
            . 'if this fails, RstFileLocator is not discovering them.',
        );
        self::assertSame(
            count($allDocuments) - $ineligibleCount,
            $coverage->totalDocuments,
        );
    }
```

- [ ] **Step 6: Run to verify both fail**

Run: `docker compose exec phpfpm composer ci:test:php:unit`
Expected: FAIL — `discoverFeatureAndImportantDocuments` fails because no
Feature/Important types are found yet;
`getCoverageOnlyCountsMatcherEligibleDocuments` fails at the
`assertGreaterThan(0, $ineligibleCount, ...)` line for the same reason.

- [ ] **Step 7: Widen `RstFileLocator`'s discovery regex**

In `src/Parser/RstFileLocator.php`, `findAll()` currently has:

```php
            $finder = new Finder();
            $finder->files()
                ->in($versionDir)
                ->name('/^(Deprecation|Breaking)-\d+.*\.rst$/')
                ->sortByName();
```

Change to:

```php
            $finder = new Finder();
            $finder->files()
                ->in($versionDir)
                ->name('/^(Deprecation|Breaking|Feature|Important)-\d+.*\.rst$/')
                ->sortByName();
```

- [ ] **Step 8: Run to verify both pass**

Run: `docker compose exec phpfpm composer ci:test:php:unit`
Expected: PASS — `discoverFeatureAndImportantDocuments` and
`getCoverageOnlyCountsMatcherEligibleDocuments` both green, and every
pre-existing test (including `findAllDocumentsForVersionRange`'s
`assertGreaterThanOrEqual(6, count($foundVersions))`, which only counts
distinct versions, not types, so it is unaffected by more documents per
version) still passes.

- [ ] **Step 9: Auto-fix, run the full CI gate, then commit**

```bash
docker compose exec phpfpm composer ci:cgl
docker compose exec phpfpm composer ci:rector
docker compose exec phpfpm composer ci:test

git add src/Parser/RstFileLocator.php tests/Integration/Parser/RstFileLocatorTest.php \
        tests/Unit/Service/DocumentServiceTest.php
git commit -m "Discover Feature and Important changelog documents"
```

`ci:cgl`/`ci:rector` run first, same reasoning as every other commit step
in this plan — they can still rewrite the code after Step 8's test run,
so `ci:test` (lint, cgl `--dry-run`, rector `--dry-run`, phpstan, unit,
cpd) must run after them, immediately before the commit. This is also the
first point in the plan where the full gate, not just the PHPUnit suite,
needs to be clean, since `RstFileLocator`'s widened regex and the
new/changed files must also pass static analysis and duplication checks.

**Operational note for whoever deploys this, not a plan step**:
`config/packages/cache.yaml` configures the default filesystem `cache.app`
pool with the comment "The data in this cache should persist between
deploys" — verified in that file. `DocumentService::getDocuments()` and
`getCoverage()` cache under `rst_documents_*`/`coverage_result_*` keys
with a 3600s TTL (`ItemInterface::expiresAfter(3600)`), not versioned by
code/commit. A deploy of this fix can therefore serve the stale
Breaking/Deprecation-only result for up to an hour afterwards, both
through the web UI and to anything reading `getCoverage()`. This plan
deliberately does not add cache-key versioning to fix that (out of scope,
same reasoning as the schema/export work) — but the deploy step should
either clear the `cache.app` pool, or whoever writes the follow-up
snapshot-schema plan should version these cache keys (e.g. by schema
version) so `snapshot:build` can never read a pre-fix cached result.

---

## Self-Review

**Spec coverage** (against the spec's §9 step 1, the sub-items this plan
claims to cover): defect 1 (affectedInstallations) → Task 1. Defect 2
(Feature/Important discovery) → Task 3. Matcher-eligibility filter,
introduced atomically with defect 2 → Task 2 (standalone) + Task 3 (wired
in, in the correct safe order). Everything else under step 1 (schema
mapping layer, `ATTRIBUTION.md`, parse-failure-fails-build,
source-file-path primary key) is explicitly out of scope per this plan's
own header and "Deliberately out of scope" note — left for a follow-up
plan, not silently dropped.

**Placeholder scan:** no TBD/TODO, no "add appropriate error handling", no
"similar to Task N" — every step has literal code or a literal shell
command.

**Type consistency:** `RstDocument`'s new `?string $affectedInstallations`
matches its use in `RstParser` (`extractSection()` already returns
`?string`) and in both new test files (`string` literal or omitted for
`null`). `MatcherEligibilityPolicy::filter(array $documents): array`
matches its call site in `DocumentService::getCoverage()` (`RstDocument[]`
in, `RstDocument[]` out, same shape `MatcherCoverageAnalyzer::analyze()`
already expects as its first argument). Constructor parameter order in
`DocumentService` matches the updated `createServiceWithFixtures()` call
site exactly (six positional `new` arguments, same order).

**All call sites found:** a repo-wide `grep -rn "new DocumentService"
--include="*.php" .` was re-run against this plan (not just recalled)
and confirms exactly two production-code constructors —
`DocumentServiceTest::createServiceWithFixtures()` and
`VersionRangeSubscriberTest::createDocumentService()`, both updated in
Task 3 Step 1 — plus historical mentions inside `docs/plans/*.md`, which
are prose, not code, and need no change.

## Execution Handoff

Plan complete and saved to
`docs/plans/2026-09-17-affected-installations-and-matcher-eligibility.md`.
Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per
task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using
executing-plans, batch execution with checkpoints.

Which approach?
