# Improve ComplexityScorer Heuristics Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Improve the ComplexityScorer heuristics so the scoring distribution is more realistic — fewer false Score 5, enable Score 1, and detect prose-based replacement patterns.

**Architecture:** Add new keyword-based rules to `ComplexityScorer::score()` between the existing mapping rules (3-4) and the fallback (Rule 7). These rules analyze migration text for common replacement phrases ("replace X with Y", "use instead", "rename to") and check for the presence of code blocks as evidence of clear migration guidance. Also relax Score 1 to allow documents with mappings but no code references.

**Tech Stack:** PHP 8.4, Symfony 7.2, PHPUnit 12

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- English PHPDoc + English inline comments

---

### Task 1: Add tests for new heuristic rules

**Files:**
- Modify: `tests/Unit/Analyzer/ComplexityScorerTest.php`

**Step 1: Add test cases for the new scoring rules**

Add these tests after the existing ones (before `createDocument`):

```php
#[Test]
public function scoreMigrationWithReplaceKeywordAndCodeBlocks(): void
{
    $doc = $this->createDocument(
        migration: 'Replace `templatePathAndFilename` with the `templateName` and `templateRootPaths` options.',
        codeBlocks: [
            new CodeBlock('yaml', "templateName: 'MyTemplate'", null),
        ],
    );

    $result = $this->scorer->score($doc);

    self::assertSame(2, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMigrationWithUseInsteadKeyword(): void
{
    $doc = $this->createDocument(
        migration: 'Use the already available icon identifiers from TYPO3.Icons instead.',
    );

    $result = $this->scorer->score($doc);

    self::assertSame(3, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMigrationWithCodeBlocksButNoRefs(): void
{
    $doc = $this->createDocument(
        migration: 'Extensions registering custom Content Objects should now use the service configuration.',
        codeBlocks: [
            new CodeBlock('yaml', "services:\n  MyVendor\\MyExt:", null),
            new CodeBlock('yaml', "services:\n  MyVendor\\MyExt:", 'After'),
        ],
    );

    $result = $this->scorer->score($doc);

    self::assertSame(2, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMigrationWithJustRemoveInstruction(): void
{
    $doc = $this->createDocument(
        migration: 'Calling this method is not needed anymore and can be removed from the affected code.',
    );

    $result = $this->scorer->score($doc);

    self::assertSame(2, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMigrationWithNoReplacementKeyword(): void
{
    $doc = $this->createDocument(
        migration: 'There is no direct replacement. Manual review of your architecture is required.',
    );

    $result = $this->scorer->score($doc);

    self::assertSame(5, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMigrationWithRenameKeyword(): void
{
    $doc = $this->createDocument(
        migration: 'Rename the files to `ext_typoscript_setup.typoscript` and `ext_typoscript_constants.typoscript`.',
    );

    $result = $this->scorer->score($doc);

    self::assertSame(2, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMappingsWithoutCodeReferences(): void
{
    $doc = $this->createDocument(
        migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
        codeReferences: [],
    );

    $result = $this->scorer->score($doc);

    // Mappings exist even without code refs — simple rename
    self::assertSame(1, $result->score);
    self::assertTrue($result->automatable);
}

#[Test]
public function scoreMigrationTextWithSwitchToKeyword(): void
{
    $doc = $this->createDocument(
        migration: 'Switch to the new PageRenderer API for adding JavaScript modules.',
    );

    $result = $this->scorer->score($doc);

    self::assertSame(3, $result->score);
    self::assertFalse($result->automatable);
}

#[Test]
public function scoreMigrationTextWithMigrateToKeyword(): void
{
    $doc = $this->createDocument(
        migration: 'Migrate to the new content element registration via TCA.',
        codeBlocks: [
            new CodeBlock('php', '$GLOBALS["TCA"]["tt_content"]["types"]["my_type"] = [...]', 'After'),
        ],
    );

    $result = $this->scorer->score($doc);

    self::assertSame(2, $result->score);
    self::assertFalse($result->automatable);
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: 9 new tests fail (score mismatches — current rules produce wrong scores for these cases).

---

### Task 2: Implement improved heuristic rules

**Files:**
- Modify: `src/Analyzer/ComplexityScorer.php`

**Step 1: Add keyword detection helpers**

Add these private methods at the end of the class (before the closing `}`):

```php
/**
 * Keywords indicating a clear, actionable replacement exists in the migration text.
 */
private const array REPLACEMENT_KEYWORDS = [
    'replace',
    'rename',
    'migrate to',
    'switch to',
    'use instead',
    'use the',
    'can be removed',
    'not needed anymore',
    'should now use',
    'has been renamed',
    'has been replaced',
    'has been moved',
];

/**
 * Keywords indicating no replacement exists — confirms Score 5.
 */
private const array NO_REPLACEMENT_KEYWORDS = [
    'no direct replacement',
    'no replacement',
    'manual review',
    'has been removed without replacement',
    'without substitute',
    'must be reworked',
    'no migration path',
];

/**
 * Check if the migration text contains clear replacement instructions.
 */
private function hasClearReplacementInstructions(string $migrationText): bool
{
    $lower = mb_strtolower($migrationText);

    return array_any(
        self::REPLACEMENT_KEYWORDS,
        static fn (string $keyword): bool => str_contains($lower, $keyword),
    );
}

/**
 * Check if the migration text explicitly states there is no replacement.
 */
private function hasNoReplacementStatement(string $migrationText): bool
{
    $lower = mb_strtolower($migrationText);

    return array_any(
        self::NO_REPLACEMENT_KEYWORDS,
        static fn (string $keyword): bool => str_contains($lower, $keyword),
    );
}
```

**Step 2: Rewrite the `score()` method with improved rules**

Replace the entire `score()` method body:

```php
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

    // Rule 3: 1:1 rename mapping exists for all references (score 1)
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

    $migrationText = $document->migration ?? '';

    // Rule 7: Explicitly states no replacement (score 5)
    if ($migrationText !== '' && $this->hasNoReplacementStatement($migrationText)) {
        return new ComplexityScore(5, 'No replacement available', false);
    }

    // Rule 8: Clear replacement keywords + code blocks (score 2)
    if ($migrationText !== '' && $this->hasClearReplacementInstructions($migrationText) && $document->codeBlocks !== []) {
        return new ComplexityScore(2, 'Replacement with code example', false);
    }

    // Rule 9: Clear replacement keywords without code blocks (score 3)
    if ($migrationText !== '' && $this->hasClearReplacementInstructions($migrationText)) {
        return new ComplexityScore(3, 'Replacement described in prose', false);
    }

    // Rule 10: Has code blocks but no replacement keywords (score 3)
    if ($migrationText !== '' && $document->codeBlocks !== []) {
        return new ComplexityScore(3, 'Code examples without clear mapping', false);
    }

    // Rule 11: Has migration text but nothing actionable (score 4)
    if ($migrationText !== '' && trim($migrationText) !== '') {
        return new ComplexityScore(4, 'Migration guidance without clear replacement', false);
    }

    // Rule 12: No migration text at all (score 5)
    return new ComplexityScore(5, 'No migration guidance', false);
}
```

**Step 3: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass including new tests.

**Step 4: Commit**

```
Improve ComplexityScorer heuristics for better scoring distribution
```

---

### Task 3: Fix existing tests that may break

**Files:**
- Modify: `tests/Unit/Analyzer/ComplexityScorerTest.php`

**Context:** Some existing tests may break because the migration text they use now matches the new keyword rules. Review each existing test case and verify the expected score still makes sense.

Specifically check:
- `scoreArchitectureChangeWithoutReplacement` — migration text is "The entire subsystem has been redesigned. Review your architecture." — this does NOT contain any `NO_REPLACEMENT_KEYWORDS` but also does not contain `REPLACEMENT_KEYWORDS`, so it falls through to Rule 11 (migration text exists → score 4). **Update assertion from 5 to 4.**

**Step 1: Fix the test assertion**

```php
#[Test]
public function scoreArchitectureChangeWithoutReplacement(): void
{
    $doc = $this->createDocument(
        migration: 'The entire subsystem has been redesigned. Review your architecture.',
        codeReferences: [],
    );

    $result = $this->scorer->score($doc);

    self::assertSame(4, $result->score);
    self::assertFalse($result->automatable);
}
```

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 3: Commit**

```
Adjust existing test expectations for improved scoring rules
```

---

### Task 4: Verify distribution improvement

**Step 1: Run distribution analysis**

Run this in the container to see the new distribution:

```bash
docker compose exec -T phpfpm php -r '
require "vendor/autoload.php";
use App\Parser\{RstParser, RstFileLocator, MatcherConfigParser};
use App\Analyzer\{MatcherCoverageAnalyzer, MigrationMappingExtractor, ComplexityScorer};
use App\Service\DocumentService;
use Symfony\Component\Cache\Adapter\NullAdapter;

$parser = new RstParser();
$locator = new RstFileLocator($parser);
$matcherParser = new MatcherConfigParser();
$coverageAnalyzer = new MatcherCoverageAnalyzer($matcherParser);
$cache = new NullAdapter();
$docService = new DocumentService($locator, $matcherParser, $coverageAnalyzer, $cache);
$scorer = new ComplexityScorer(new MigrationMappingExtractor());
$docs = $docService->getDocuments();
$dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
$auto = 0;
foreach ($docs as $doc) {
    $s = $scorer->score($doc);
    $dist[$s->score]++;
    if ($s->automatable) $auto++;
}
echo "New Distribution:\n";
foreach ($dist as $s => $c) echo "  Score $s: $c (" . round($c/count($docs)*100,1) . "%)\n";
echo "Automatable: $auto (" . round($auto/count($docs)*100,1) . "%)\n";
echo "Total: " . count($docs) . "\n";
'
```

Expected: Score 5 drops from 144 (~34%) to ~20-40, Score 1 appears, Score 2/3 grow.

**Step 2: If distribution is still skewed, adjust constants and re-test**

No commit for this task — it's a verification step.

---

### Task 5: Code review and cleanup

**Step 1: Review all changes**

- Check `score()` method for SOLID compliance — rule ordering matters (early returns)
- Verify no false positives: "no replacement" keywords should not accidentally match on "use the" in unrelated context
- Check that existing tests all still make semantic sense
- Run full CI suite

**Step 2: Fix any findings and commit**

```
Review findings: [describe fixes]
```
