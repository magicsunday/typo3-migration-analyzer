# Hybrid-Scoring & LLM Rector-Rule-Generator Design

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Combine heuristic and LLM complexity scores with LLM-priority, and generate runnable Rector Rules from LLM code mappings with test scaffolding.

**Architecture:** Two independent features sharing the existing scoring and generation infrastructure. Feature 1 extends `ComplexityScore` with an optional heuristic fallback score. Feature 2 introduces a new `LlmRectorRuleGenerator` that produces Config-Rules and full Rule-classes from `LlmAnalysisResult` data.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit 13, nikic/php-parser (existing dependency via Rector)

---

## Feature 1: Hybrid-Scoring

### Problem

The `ComplexityScorer` currently returns the LLM score directly when available, discarding the heuristic score. There is no way to detect when LLM and heuristic scores diverge significantly, which would indicate potential LLM misclassification.

### Design

**ComplexityScore DTO** — Add optional `?int $heuristicScore` field:

```php
final readonly class ComplexityScore
{
    public function __construct(
        public int $score,
        public string $reason,
        public bool $automatable,
        public ?int $heuristicScore = null,
    ) {
    }

    public function isLlmBased(): bool
    {
        return $this->heuristicScore !== null;
    }

    public function scoreDivergence(): int
    {
        return $this->heuristicScore !== null
            ? abs($this->score - $this->heuristicScore)
            : 0;
    }
}
```

**ComplexityScorer** — Always compute heuristic. When LLM result exists, use LLM as primary score, attach heuristic as secondary:

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

private function scoreByHeuristic(RstDocument $document): ComplexityScore
{
    // Existing 12 heuristic rules, extracted into private method
}
```

**Detail template** — Show heuristic badge next to main score when they diverge:

```
[Complexity 4/5]  [Heuristik: 2/5]  ← only shown when scores differ
```

### Files Affected

- `src/Dto/ComplexityScore.php` — Add `heuristicScore`, `isLlmBased()`, `scoreDivergence()`
- `src/Analyzer/ComplexityScorer.php` — Extract `scoreByHeuristic()`, always compute both
- `templates/deprecation/detail.html.twig` — Show divergence badge
- `tests/Unit/Dto/ComplexityScoreTest.php` — New tests for helper methods
- `tests/Unit/Analyzer/ComplexityScorerTest.php` — Update existing tests

---

## Feature 2: LLM Rector-Rule-Generator

### Problem

The existing `RectorRuleGenerator` only uses regex-extracted `MigrationMapping` objects (~100 mappings). The LLM has produced 2,204 `codeMappings` with type information and 227 "Rector feasible" assessments that are currently unused for rule generation.

### Design

**LlmRectorRule DTO** — Represents a generated rule with optional test scaffolding:

```php
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

**LlmRectorRuleGenerator** — Processes `LlmAnalysisResult` + `RstDocument`:

Config-Rules (directly usable rector.php entries) from mapping types:
- `class_rename` → `RenameClassRector`
- `method_rename` → `RenameMethodRector`
- `constant_rename` → `RenameClassConstFetchRector`
- `class_removal` → Config-based removal

Rule-Classes (skeleton with Before/After) for complex types:
- `hook_to_event`, `argument_change`, `method_removal`, `tca_change`, `behavior_change`, `typoscript_change`
- Before/After code from `RstDocument::codeBlocks` as PHPUnit test fixtures
- Class name from `LlmRectorAssessment::ruleType` if available, else generated from filename

**RectorConfigRenderer** — Extracted from existing `RectorRuleGenerator::renderConfig()`:
- Shared service for rendering `rector.php` config files
- Used by both `RectorRuleGenerator` (regex-based) and `LlmRectorRuleGenerator` (LLM-based)

### Export

**Single export** (detail page):
- New button "Rector Rule" when LLM analysis available
- Route: `GET /rector/llm-export/{filename}`
- Downloads PHP file(s) for that document

**Bulk export** (action plan):
- ZIP containing:
  - `rector.php` — combined config for all Config-Rules
  - `rules/` — Rule classes for complex patterns
  - `tests/` — PHPUnit tests with Before/After fixtures

### Files Affected

- `src/Dto/LlmRectorRule.php` — New DTO
- `src/Generator/LlmRectorRuleGenerator.php` — New generator
- `src/Generator/RectorConfigRenderer.php` — Extracted from RectorRuleGenerator
- `src/Generator/RectorRuleGenerator.php` — Refactored to use RectorConfigRenderer
- `src/Controller/RectorController.php` — New routes for LLM-based export
- `templates/deprecation/detail.html.twig` — Add export button
- `tests/Unit/Generator/LlmRectorRuleGeneratorTest.php` — New tests
- `tests/Unit/Generator/RectorConfigRendererTest.php` — New tests

---

## Decisions

| Question | Decision |
|----------|----------|
| LLM vs. Heuristic priority | LLM always wins, heuristic is fallback |
| Score divergence visibility | Show in detail view when scores differ |
| Rule generation scope | Config-Rules + full Rule-classes with tests |
| Extra LLM call for rule code | No — use existing LLM data only |
| Export options | Both single (per document) and bulk (ZIP) |
| Generator architecture | New `LlmRectorRuleGenerator`, shared `RectorConfigRenderer` |
