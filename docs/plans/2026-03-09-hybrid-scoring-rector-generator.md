# Hybrid-Scoring & LLM Rector-Rule-Generator Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Combine heuristic and LLM complexity scores (LLM-priority) with divergence display, and generate runnable Rector Rules with test scaffolding from LLM code mappings.

**Architecture:** Feature 1 extends `ComplexityScore` DTO with heuristic fallback and refactors `ComplexityScorer` to always compute both. Feature 2 adds `LlmRectorRuleGenerator` service that produces Config-Rules and Rule-classes from `LlmAnalysisResult`, with shared config rendering extracted into `RectorConfigRenderer`.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit 13, nikic/php-parser (via Rector)

---

### Task 1: Extend ComplexityScore DTO

**Files:**
- Modify: `src/Dto/ComplexityScore.php`
- Test: `tests/Unit/Dto/ComplexityScoreTest.php`

**Step 1: Write the failing tests**

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
    public function isLlmBasedReturnsFalseWhenNoHeuristicScore(): void
    {
        $score = new ComplexityScore(3, 'Some reason', false);

        self::assertFalse($score->isLlmBased());
    }

    #[Test]
    public function isLlmBasedReturnsTrueWhenHeuristicScorePresent(): void
    {
        $score = new ComplexityScore(3, 'LLM reason', false, heuristicScore: 5);

        self::assertTrue($score->isLlmBased());
    }

    #[Test]
    public function scoreDivergenceReturnsZeroWithoutHeuristicScore(): void
    {
        $score = new ComplexityScore(3, 'Some reason', false);

        self::assertSame(0, $score->scoreDivergence());
    }

    #[Test]
    public function scoreDivergenceReturnsAbsoluteDifference(): void
    {
        $score = new ComplexityScore(2, 'LLM reason', true, heuristicScore: 5);

        self::assertSame(3, $score->scoreDivergence());
    }

    #[Test]
    public function scoreDivergenceReturnsZeroWhenScoresMatch(): void
    {
        $score = new ComplexityScore(3, 'LLM reason', false, heuristicScore: 3);

        self::assertSame(0, $score->scoreDivergence());
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Dto/ComplexityScoreTest.php`
Expected: FAIL — methods `isLlmBased()` and `scoreDivergence()` don't exist, `heuristicScore` parameter unknown.

**Step 3: Implement the DTO changes**

Update `src/Dto/ComplexityScore.php`:

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

use function abs;

/**
 * Represents the complexity assessment of a migration document.
 *
 * Score scale:
 *   1 = trivial (class/method renamed, 1:1 mapping)
 *   2 = easy (method removed with clear replacement)
 *   3 = medium (argument signature changed)
 *   4 = complex (hook->event migration, TCA restructure)
 *   5 = manual (architecture change without clear replacement)
 *
 * When an LLM analysis result is available, the primary score reflects the LLM
 * assessment and heuristicScore holds the rule-based score for comparison.
 */
final readonly class ComplexityScore
{
    public function __construct(
        public int $score,
        public string $reason,
        public bool $automatable,
        public ?int $heuristicScore = null,
    ) {
    }

    /**
     * Whether the primary score comes from an LLM analysis.
     */
    public function isLlmBased(): bool
    {
        return $this->heuristicScore !== null;
    }

    /**
     * Absolute difference between LLM and heuristic score, or 0 if heuristic-only.
     */
    public function scoreDivergence(): int
    {
        return $this->heuristicScore !== null
            ? abs($this->score - $this->heuristicScore)
            : 0;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Dto/ComplexityScoreTest.php`
Expected: PASS (5 tests)

**Step 5: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Dto/ComplexityScore.php tests/Unit/Dto/ComplexityScoreTest.php && git commit -m "Add heuristicScore field and helper methods to ComplexityScore"`

---

### Task 2: Refactor ComplexityScorer for hybrid scoring

**Files:**
- Modify: `src/Analyzer/ComplexityScorer.php`
- Modify: `tests/Unit/Analyzer/ComplexityScorerTest.php`

**Step 1: Write the failing test**

Add to `tests/Unit/Analyzer/ComplexityScorerTest.php`:

```php
#[Test]
public function scoreLlmResultIncludesHeuristicScore(): void
{
    $repository = new LlmResultRepository(':memory:');
    $scorer     = new ComplexityScorer(new MigrationMappingExtractor(), $repository);

    $repository->save(new LlmAnalysisResult(
        filename: 'Deprecation-99999-Test.rst',
        modelId: 'claude-4',
        promptVersion: '1.0',
        score: 2,
        automationGrade: AutomationGrade::Partial,
        summary: 'LLM says partially automatable',
        reasoning: '',
        migrationSteps: [],
        affectedAreas: [],
        affectedComponents: [],
        codeMappings: [],
        rectorAssessment: null,
        tokensInput: 100,
        tokensOutput: 50,
        durationMs: 500,
        createdAt: '2026-03-09 08:00:00',
    ));

    // Heuristic would score 5 (no migration text, no refs)
    $doc    = $this->createDocument(migration: null, codeReferences: []);
    $result = $scorer->score($doc);

    self::assertSame(2, $result->score);
    self::assertTrue($result->isLlmBased());
    self::assertSame(5, $result->heuristicScore);
    self::assertSame(3, $result->scoreDivergence());
}

#[Test]
public function scoreHeuristicOnlyHasNoHeuristicScoreField(): void
{
    $doc    = $this->createDocument(migration: null, codeReferences: []);
    $result = $this->scorer->score($doc);

    self::assertFalse($result->isLlmBased());
    self::assertNull($result->heuristicScore);
    self::assertSame(0, $result->scoreDivergence());
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Analyzer/ComplexityScorerTest.php --filter="scoreLlmResultIncludesHeuristicScore|scoreHeuristicOnlyHasNoHeuristicScoreField"`
Expected: FAIL — `heuristicScore` is always null in current implementation.

**Step 3: Refactor ComplexityScorer**

Update `src/Analyzer/ComplexityScorer.php`. Extract the existing heuristic logic into `scoreByHeuristic()` and always compute both:

In the `score()` method, replace lines 84-158 with:

```php
public function score(RstDocument $document): ComplexityScore
{
    $heuristic = $this->scoreByHeuristic($document);
    $llmResult = $this->repository->findLatest($document->filename);

    if ($llmResult instanceof LlmAnalysisResult) {
        return new ComplexityScore(
            score: $llmResult->score,
            reason: $llmResult->summary,
            automatable: $llmResult->automationGrade !== AutomationGrade::Manual,
            heuristicScore: $heuristic->score,
        );
    }

    return $heuristic;
}

/**
 * Score the document using rule-based heuristic analysis.
 */
private function scoreByHeuristic(RstDocument $document): ComplexityScore
{
    // Rule 1: Hook → Event migration (score 4)
    if ($this->isHookToEventMigration($document)) {
        return new ComplexityScore(4, 'Hook to event migration', false);
    }

    // Rule 2: TCA restructure (score 4)
    if ($this->isTcaChange($document)) {
        return new ComplexityScore(4, 'TCA structure change', false);
    }

    $mappings = $this->extractor->extract($document->migration, $document->description);

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

    $migrationText = trim($document->migration ?? '');

    // Rule 7: No or trivially short migration text (score 5)
    if ($migrationText === '' || mb_strlen($migrationText) <= 10) {
        return new ComplexityScore(5, 'No migration guidance', false);
    }

    // Rule 8: Explicitly states no replacement (score 5)
    if ($this->hasNoReplacementStatement($migrationText)) {
        return new ComplexityScore(5, 'No replacement available', false);
    }

    // Rule 9: Clear replacement keywords + code blocks (score 2)
    if ($this->hasClearReplacementInstructions($migrationText) && $document->codeBlocks !== []) {
        return new ComplexityScore(2, 'Replacement with code example', false);
    }

    // Rule 10: Clear replacement keywords without code blocks (score 3)
    if ($this->hasClearReplacementInstructions($migrationText)) {
        return new ComplexityScore(3, 'Replacement described in prose', false);
    }

    // Rule 11: Has code blocks but no replacement keywords (score 3)
    if ($document->codeBlocks !== []) {
        return new ComplexityScore(3, 'Code examples without clear mapping', false);
    }

    // Rule 12: Has migration text but nothing actionable (score 4)
    return new ComplexityScore(4, 'Migration guidance without clear replacement', false);
}
```

The private helper methods (`isHookToEventMigration`, `isTcaChange`, etc.) remain unchanged.

Also add the missing `mb_strlen` import:

```php
use function mb_strlen;
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Analyzer/ComplexityScorerTest.php`
Expected: PASS (all tests including existing ones — the existing `scorePrefersLlmResultOverHeuristic` test still works because `score`, `reason`, and `automatable` are unchanged)

**Step 5: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Analyzer/ComplexityScorer.php tests/Unit/Analyzer/ComplexityScorerTest.php && git commit -m "Always compute heuristic score alongside LLM score for divergence detection"`

---

### Task 3: Show score divergence in detail template

**Files:**
- Modify: `templates/deprecation/detail.html.twig`

**Step 1: Update the template**

In `templates/deprecation/detail.html.twig`, find the complexity badges section (around lines 29-40). After the existing complexity badge, add the heuristic divergence badge:

Replace:

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

With:

```twig
{% if complexity is defined %}
    {% if complexity.score <= 2 %}
        <span class="badge text-bg-success" title="{{ complexity.reason }}">Complexity {{ complexity.score }}/5</span>
    {% elseif complexity.score == 3 %}
        <span class="badge text-bg-warning" title="{{ complexity.reason }}">Complexity {{ complexity.score }}/5</span>
    {% else %}
        <span class="badge text-bg-danger" title="{{ complexity.reason }}">Complexity {{ complexity.score }}/5</span>
    {% endif %}
    {% if complexity.isLlmBased %}
        <span class="badge text-bg-primary" title="Score basiert auf LLM-Analyse">
            <i class="bi bi-robot me-1"></i>LLM
        </span>
    {% endif %}
    {% if complexity.scoreDivergence > 0 %}
        <span class="badge text-bg-secondary" title="Heuristik-Score weicht ab (Differenz: {{ complexity.scoreDivergence }})">
            Heuristik: {{ complexity.heuristicScore }}/5
        </span>
    {% endif %}
    {% if complexity.automatable %}
        <span class="badge text-bg-info">Automatable</span>
    {% endif %}
{% endif %}
```

**Step 2: Verify manually**

Open a detail page for a document that has an LLM result (e.g., `https://analyzer.nas.lan/deprecations/Breaking-98024-TCA-option-cruserid-removed.rst`). You should see:
- Main complexity badge with LLM score
- Blue "LLM" badge
- Grey "Heuristik: X/5" badge if the scores differ

**Step 3: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add templates/deprecation/detail.html.twig && git commit -m "Show LLM source badge and heuristic score divergence in detail view"`

---

### Task 4: Create LlmRectorRule DTO

**Files:**
- Create: `src/Dto/LlmRectorRule.php`
- Test: `tests/Unit/Dto/LlmRectorRuleTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Dto/LlmRectorRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\LlmRectorRule;
use App\Dto\RectorRuleType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LlmRectorRule::class)]
final class LlmRectorRuleTest extends TestCase
{
    #[Test]
    public function configRuleHasConfigPhpButNoRulePhp(): void
    {
        $rule = new LlmRectorRule(
            filename: 'Deprecation-12345-Test.rst',
            type: RectorRuleType::RenameClass,
            ruleClassName: 'RenameClassRector',
            configPhp: "'Old\\Class' => 'New\\Class'",
            rulePhp: null,
            testPhp: null,
            fixtureBeforePhp: null,
            fixtureAfterPhp: null,
        );

        self::assertSame(RectorRuleType::RenameClass, $rule->type);
        self::assertNotNull($rule->configPhp);
        self::assertNull($rule->rulePhp);
    }

    #[Test]
    public function skeletonRuleHasRulePhpAndTestPhp(): void
    {
        $rule = new LlmRectorRule(
            filename: 'Breaking-12345-Test.rst',
            type: RectorRuleType::Skeleton,
            ruleClassName: 'TestRector',
            configPhp: null,
            rulePhp: '<?php class TestRector {}',
            testPhp: '<?php class TestRectorTest {}',
            fixtureBeforePhp: '<?php $old->method();',
            fixtureAfterPhp: '<?php $new->method();',
        );

        self::assertSame(RectorRuleType::Skeleton, $rule->type);
        self::assertNull($rule->configPhp);
        self::assertNotNull($rule->rulePhp);
        self::assertNotNull($rule->testPhp);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Dto/LlmRectorRuleTest.php`
Expected: FAIL — class `LlmRectorRule` does not exist.

**Step 3: Implement the DTO**

Create `src/Dto/LlmRectorRule.php`:

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
 * Represents a Rector rule generated from LLM analysis data.
 *
 * Config-type rules have configPhp set (rector.php entry).
 * Skeleton-type rules have rulePhp/testPhp/fixtures set (full Rule class with test).
 */
final readonly class LlmRectorRule
{
    public function __construct(
        public string $filename,
        public RectorRuleType $type,
        public string $ruleClassName,
        public ?string $configPhp,
        public ?string $rulePhp,
        public ?string $testPhp,
        public ?string $fixtureBeforePhp,
        public ?string $fixtureAfterPhp,
    ) {
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Dto/LlmRectorRuleTest.php`
Expected: PASS (2 tests)

**Step 5: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Dto/LlmRectorRule.php tests/Unit/Dto/LlmRectorRuleTest.php && git commit -m "Add LlmRectorRule DTO for LLM-based Rector rule generation"`

---

### Task 5: Extract RectorConfigRenderer

**Files:**
- Create: `src/Generator/RectorConfigRenderer.php`
- Modify: `src/Generator/RectorRuleGenerator.php`
- Test: `tests/Unit/Generator/RectorConfigRendererTest.php`

**Step 1: Write the failing test**

Create `tests/Unit/Generator/RectorConfigRendererTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Generator\RectorConfigRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RectorConfigRenderer::class)]
final class RectorConfigRendererTest extends TestCase
{
    #[Test]
    public function renderEmptyEntriesReturnsEmptyString(): void
    {
        $renderer = new RectorConfigRenderer();

        self::assertSame('', $renderer->render([]));
    }

    #[Test]
    public function renderClassRenameConfig(): void
    {
        $renderer = new RectorConfigRenderer();
        $entries  = [
            [
                'type' => 'rename_class',
                'old'  => 'TYPO3\CMS\Core\OldClass',
                'new'  => 'TYPO3\CMS\Core\NewClass',
            ],
        ];

        $output = $renderer->render($entries);

        self::assertStringContainsString('RenameClassRector', $output);
        self::assertStringContainsString("'TYPO3\\CMS\\Core\\OldClass' => 'TYPO3\\CMS\\Core\\NewClass'", $output);
        self::assertStringContainsString('RectorConfig::configure()', $output);
    }

    #[Test]
    public function renderMethodRenameConfig(): void
    {
        $renderer = new RectorConfigRenderer();
        $entries  = [
            [
                'type'      => 'rename_method',
                'className' => 'TYPO3\CMS\Core\Foo',
                'oldMethod' => 'oldMethod',
                'newMethod' => 'newMethod',
            ],
        ];

        $output = $renderer->render($entries);

        self::assertStringContainsString('RenameMethodRector', $output);
        self::assertStringContainsString('MethodCallRename', $output);
    }

    #[Test]
    public function renderMultipleRuleTypes(): void
    {
        $renderer = new RectorConfigRenderer();
        $entries  = [
            [
                'type' => 'rename_class',
                'old'  => 'Old\\A',
                'new'  => 'New\\A',
            ],
            [
                'type' => 'rename_class',
                'old'  => 'Old\\B',
                'new'  => 'New\\B',
            ],
            [
                'type'      => 'rename_method',
                'className' => 'TYPO3\\Foo',
                'oldMethod' => 'bar',
                'newMethod' => 'baz',
            ],
        ];

        $output = $renderer->render($entries);

        self::assertStringContainsString('RenameClassRector', $output);
        self::assertStringContainsString('RenameMethodRector', $output);
        self::assertStringContainsString("'Old\\A' => 'New\\A'", $output);
        self::assertStringContainsString("'Old\\B' => 'New\\B'", $output);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Generator/RectorConfigRendererTest.php`
Expected: FAIL — class does not exist.

**Step 3: Implement RectorConfigRenderer**

Create `src/Generator/RectorConfigRenderer.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Generator;

use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\Rector\StaticCall\RenameStaticMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;
use Rector\Renaming\ValueObject\RenameClassAndConstFetch;
use Rector\Renaming\ValueObject\RenameStaticMethod;

use function array_unique;
use function array_values;
use function sort;
use function sprintf;
use function str_replace;

/**
 * Renders rector.php configuration files from structured rule entries.
 *
 * Accepts an array of rule entry arrays with type-specific keys and produces
 * a complete rector.php file with all necessary imports and configuration.
 *
 * Supported entry types:
 *   - rename_class: {type, old, new}
 *   - rename_method: {type, className, oldMethod, newMethod}
 *   - rename_static_method: {type, oldClass, oldMethod, newClass, newMethod}
 *   - rename_class_constant: {type, oldClass, oldConstant, newClass, newConstant}
 */
final class RectorConfigRenderer
{
    /**
     * Render a rector.php configuration file from rule entries.
     *
     * @param list<array<string, string>> $entries
     */
    public function render(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $imports = [RectorConfig::class];
        $groups  = [];

        foreach ($entries as $entry) {
            [$shortName, $entryImports, $configLine] = $this->resolveEntry($entry);

            if ($shortName === '') {
                continue;
            }

            foreach ($entryImports as $import) {
                $imports[] = $import;
            }

            $groups[$shortName][] = $configLine;
        }

        if ($groups === []) {
            return '';
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = "<?php\n\ndeclare(strict_types=1);\n\n";

        foreach ($imports as $import) {
            $output .= sprintf("use %s;\n", $import);
        }

        $output .= "\nreturn RectorConfig::configure()\n";
        $output .= "    ->withConfiguredRules([\n";

        foreach ($groups as $shortName => $lines) {
            $output .= sprintf("        %s::class => [\n", $shortName);

            foreach ($lines as $line) {
                $output .= sprintf("            %s,\n", $line);
            }

            $output .= "        ],\n";
        }

        return $output . "    ]);\n";
    }

    /**
     * Resolve a single entry into Rector class name, imports, and config line.
     *
     * @param array<string, string> $entry
     *
     * @return array{string, list<string>, string}
     */
    private function resolveEntry(array $entry): array
    {
        return match ($entry['type'] ?? '') {
            'rename_class' => [
                'RenameClassRector',
                [RenameClassRector::class],
                sprintf("'%s' => '%s'", $this->escape($entry['old'] ?? ''), $this->escape($entry['new'] ?? '')),
            ],
            'rename_method' => [
                'RenameMethodRector',
                [RenameMethodRector::class, MethodCallRename::class],
                sprintf(
                    "new MethodCallRename('%s', '%s', '%s')",
                    $this->escape($entry['className'] ?? ''),
                    $this->escape($entry['oldMethod'] ?? ''),
                    $this->escape($entry['newMethod'] ?? ''),
                ),
            ],
            'rename_static_method' => [
                'RenameStaticMethodRector',
                [RenameStaticMethodRector::class, RenameStaticMethod::class],
                sprintf(
                    "new RenameStaticMethod('%s', '%s', '%s', '%s')",
                    $this->escape($entry['oldClass'] ?? ''),
                    $this->escape($entry['oldMethod'] ?? ''),
                    $this->escape($entry['newClass'] ?? ''),
                    $this->escape($entry['newMethod'] ?? ''),
                ),
            ],
            'rename_class_constant' => [
                'RenameClassConstFetchRector',
                [RenameClassConstFetchRector::class, RenameClassAndConstFetch::class],
                sprintf(
                    "new RenameClassAndConstFetch('%s', '%s', '%s', '%s')",
                    $this->escape($entry['oldClass'] ?? ''),
                    $this->escape($entry['oldConstant'] ?? ''),
                    $this->escape($entry['newClass'] ?? ''),
                    $this->escape($entry['newConstant'] ?? ''),
                ),
            ],
            default => ['', [], ''],
        };
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Generator/RectorConfigRendererTest.php`
Expected: PASS (4 tests)

**Step 5: Refactor RectorRuleGenerator to use RectorConfigRenderer**

In `src/Generator/RectorRuleGenerator.php`, inject `RectorConfigRenderer` and delegate `renderConfig()` to it. The method signature and output remain identical — this is a pure refactoring.

**Step 6: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Generator/RectorConfigRenderer.php src/Generator/RectorRuleGenerator.php tests/Unit/Generator/RectorConfigRendererTest.php && git commit -m "Extract RectorConfigRenderer from RectorRuleGenerator for shared use"`

---

### Task 6: Create LlmRectorRuleGenerator

**Files:**
- Create: `src/Generator/LlmRectorRuleGenerator.php`
- Test: `tests/Unit/Generator/LlmRectorRuleGeneratorTest.php`

**Step 1: Write the failing tests**

Create `tests/Unit/Generator/LlmRectorRuleGeneratorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Dto\AutomationGrade;
use App\Dto\CodeBlock;
use App\Dto\DocumentType;
use App\Dto\LlmAnalysisResult;
use App\Dto\LlmCodeMapping;
use App\Dto\LlmRectorAssessment;
use App\Dto\LlmRectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\LlmRectorRuleGenerator;
use App\Generator\RectorConfigRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

#[CoversClass(LlmRectorRuleGenerator::class)]
final class LlmRectorRuleGeneratorTest extends TestCase
{
    private LlmRectorRuleGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new LlmRectorRuleGenerator(new RectorConfigRenderer());
    }

    #[Test]
    public function generateClassRenameProducesConfigRule(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\OldClass', 'TYPO3\CMS\Core\NewClass', 'class_rename'),
        ]);

        $rules       = $this->generator->generate($result, $this->createDocument());
        $configRules = $this->filterByType($rules, RectorRuleType::RenameClass);

        self::assertCount(1, $configRules);
        self::assertSame('RenameClassRector', $configRules[0]->ruleClassName);
        self::assertNotNull($configRules[0]->configPhp);
        self::assertNull($configRules[0]->rulePhp);
    }

    #[Test]
    public function generateMethodRenameProducesConfigRule(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\Foo::oldMethod', 'TYPO3\CMS\Core\Foo::newMethod', 'method_rename'),
        ]);

        $rules       = $this->generator->generate($result, $this->createDocument());
        $configRules = $this->filterByType($rules, RectorRuleType::RenameMethod);

        self::assertCount(1, $configRules);
        self::assertSame('RenameMethodRector', $configRules[0]->ruleClassName);
    }

    #[Test]
    public function generateHookToEventProducesSkeletonRule(): void
    {
        $result = $this->createLlmResult(
            [new LlmCodeMapping('$GLOBALS[TYPO3_CONF_VARS][SC_OPTIONS]', 'MyEvent', 'hook_to_event')],
            new LlmRectorAssessment(true, 'CustomRector', 'Needs custom rule'),
        );

        $doc   = $this->createDocument(codeBlocks: [
            new CodeBlock('php', '$GLOBALS[\'TYPO3_CONF_VARS\'][\'SC_OPTIONS\'] = ...;', 'Before'),
            new CodeBlock('php', '$eventDispatcher->dispatch(new MyEvent());', 'After'),
        ]);
        $rules = $this->generator->generate($result, $doc);

        $skeletons = $this->filterByType($rules, RectorRuleType::Skeleton);

        self::assertCount(1, $skeletons);
        self::assertNotNull($skeletons[0]->rulePhp);
        self::assertStringContainsString('AbstractRector', $skeletons[0]->rulePhp);
        self::assertNotNull($skeletons[0]->testPhp);
        self::assertNotNull($skeletons[0]->fixtureBeforePhp);
        self::assertNotNull($skeletons[0]->fixtureAfterPhp);
    }

    #[Test]
    public function generateWithNoMappingsReturnsEmpty(): void
    {
        $result = $this->createLlmResult([]);
        $rules  = $this->generator->generate($result, $this->createDocument());

        self::assertSame([], $rules);
    }

    #[Test]
    public function renderCombinedConfigRendersAllConfigRules(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('Old\A', 'New\A', 'class_rename'),
            new LlmCodeMapping('Old\B', 'New\B', 'class_rename'),
        ]);

        $rules  = $this->generator->generate($result, $this->createDocument());
        $config = $this->generator->renderCombinedConfig($rules);

        self::assertStringContainsString('RenameClassRector', $config);
        self::assertStringContainsString("'Old\\A'", $config);
        self::assertStringContainsString("'Old\\B'", $config);
    }

    /**
     * @param list<LlmCodeMapping> $mappings
     */
    private function createLlmResult(
        array $mappings,
        ?LlmRectorAssessment $assessment = null,
    ): LlmAnalysisResult {
        return new LlmAnalysisResult(
            filename: 'Deprecation-12345-Test.rst',
            modelId: 'claude-haiku',
            promptVersion: '1.0',
            score: 2,
            automationGrade: AutomationGrade::Partial,
            summary: 'Test summary',
            reasoning: '',
            migrationSteps: [],
            affectedAreas: [],
            affectedComponents: [],
            codeMappings: $mappings,
            rectorAssessment: $assessment,
            tokensInput: 100,
            tokensOutput: 50,
            durationMs: 500,
            createdAt: '2026-03-09 12:00:00',
        );
    }

    /**
     * @param list<CodeBlock> $codeBlocks
     */
    private function createDocument(array $codeBlocks = []): RstDocument
    {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 12345,
            title: 'Test deprecation',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: 'Use the new API.',
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-12345-Test.rst',
            codeBlocks: $codeBlocks,
        );
    }

    /**
     * @param list<LlmRectorRule> $rules
     *
     * @return list<LlmRectorRule>
     */
    private function filterByType(array $rules, RectorRuleType $type): array
    {
        return array_values(array_filter(
            $rules,
            static fn (LlmRectorRule $r): bool => $r->type === $type,
        ));
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Generator/LlmRectorRuleGeneratorTest.php`
Expected: FAIL — class does not exist.

**Step 3: Implement LlmRectorRuleGenerator**

Create `src/Generator/LlmRectorRuleGenerator.php`. This is the core generator that:

1. Splits `LlmCodeMapping` entries into config-type (class_rename, method_rename, constant_rename) and skeleton-type (everything else)
2. For config-type: produces `LlmRectorRule` with `configPhp` set
3. For skeleton-type: produces `LlmRectorRule` with full `rulePhp`, `testPhp`, and Before/After fixtures from `RstDocument::codeBlocks`
4. Parses `ClassName::member` format from `LlmCodeMapping::old`/`::new` to extract class and method names
5. Uses `LlmRectorAssessment::ruleType` for class name when available, otherwise generates from filename

Key implementation details:
- Config entry format for `class_rename`: `['type' => 'rename_class', 'old' => ..., 'new' => ...]`
- Config entry format for `method_rename`: `['type' => 'rename_method', 'className' => ..., 'oldMethod' => ..., 'newMethod' => ...]`
- Skeleton class rendering uses the same pattern as `RectorRuleGenerator::renderSkeleton()` but with Before/After code from codeBlocks as CodeSample arguments
- Test rendering generates a PHPUnit test class that uses `AbstractRectorTestCase`
- `renderCombinedConfig()` takes `list<LlmRectorRule>`, filters config-type rules, delegates to `RectorConfigRenderer::render()`

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm php vendor/bin/phpunit tests/Unit/Generator/LlmRectorRuleGeneratorTest.php`
Expected: PASS (5 tests)

**Step 5: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Generator/LlmRectorRuleGenerator.php tests/Unit/Generator/LlmRectorRuleGeneratorTest.php && git commit -m "Add LlmRectorRuleGenerator for producing Rector rules from LLM data"`

---

### Task 7: Add single Rector export route

**Files:**
- Modify: `src/Controller/DeprecationController.php`
- Modify: `templates/deprecation/detail.html.twig`

**Step 1: Add export route to controller**

Add a new route to `src/Controller/DeprecationController.php`:

```php
#[Route('/rector/llm-export/{filename}', name: 'rector_llm_export', requirements: ['filename' => '[A-Za-z0-9_.\-]+\.rst'])]
public function rectorLlmExport(
    string $filename,
    DocumentService $documentService,
    LlmAnalysisService $llmService,
    LlmRectorRuleGenerator $rectorGenerator,
): Response {
    $doc = $documentService->findDocumentByFilename($filename);

    if (!$doc instanceof RstDocument) {
        throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
    }

    $llmResult = $llmService->getLatestResult($filename);

    if (!$llmResult instanceof LlmAnalysisResult) {
        throw $this->createNotFoundException('No LLM analysis available.');
    }

    $rules = $rectorGenerator->generate($llmResult, $doc);

    if ($rules === []) {
        throw $this->createNotFoundException('No Rector rules could be generated.');
    }

    // Build combined output
    $config    = $rectorGenerator->renderCombinedConfig($rules);
    $skeletons = array_filter($rules, static fn (LlmRectorRule $r): bool => $r->type === RectorRuleType::Skeleton);

    if ($skeletons === [] && $config !== '') {
        // Only config rules — return rector.php directly
        return new Response($config, 200, [
            'Content-Type'        => 'application/x-php',
            'Content-Disposition' => 'attachment; filename="rector.php"',
        ]);
    }

    // Multiple files — create ZIP
    // ... (ZIP creation with config + skeletons + tests + fixtures)
}
```

**Step 2: Add export button to detail template**

In `templates/deprecation/detail.html.twig`, after the LLM Analysis card, add a Rector export button when LLM result is available:

```twig
{% if llmResult is defined and llmResult %}
    <a href="{{ path('rector_llm_export', {filename: doc.filename}) }}"
       class="btn btn-sm btn-outline-primary mt-2">
        <i class="bi bi-download me-1"></i>Rector Rule exportieren
    </a>
{% endif %}
```

**Step 3: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Controller/DeprecationController.php templates/deprecation/detail.html.twig && git commit -m "Add single-document Rector rule export from LLM analysis"`

---

### Task 8: Add bulk Rector export to action plan

**Files:**
- Modify: `src/Controller/ScanController.php` (or wherever the action plan export lives)

**Step 1: Identify the existing export route**

Find the action plan controller with `grep -r 'action.*plan\|ActionPlan' src/Controller/`. The bulk export should:

1. Iterate all action items with LLM results
2. Generate rules for each
3. Bundle config + skeletons + tests into a ZIP
4. Use `RectorConfigRenderer` for the combined rector.php

**Step 2: Implement the bulk export**

Add a new route like `POST /action-plan/rector-export` that:
- Gets all documents from `DocumentService`
- For each document with an LLM result, calls `LlmRectorRuleGenerator::generate()`
- Collects all rules, generates combined `rector.php` via `renderCombinedConfig()`
- Creates ZIP with `rector.php` + `rules/` + `tests/` + `fixtures/`
- Returns ZIP download

**Step 3: Run full CI and commit**

Run: `docker compose exec -T phpfpm composer ci:cgl && docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Commit: `git add src/Controller/... && git commit -m "Add bulk Rector rule export from action plan with LLM data"`
