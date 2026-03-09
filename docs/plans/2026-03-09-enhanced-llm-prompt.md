# Enhanced LLM Prompt — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Optimize the LLM analysis prompt and extend the data model with `codeMappings` and `rectorAssessment` for machine-readable automation data.

**Architecture:** Two new lightweight DTOs (`LlmCodeMapping`, `LlmRectorAssessment`) store structured LLM output. Stored as JSON TEXT columns in the existing SQLite table. The prompt is updated in `getDefaultPrompt()` — existing analyses remain valid, new prompt version triggers re-analysis. UI extended to display mappings and Rector assessment.

**Tech Stack:** PHP 8.4, Symfony 7.4, SQLite, Twig, Bootstrap 5.3, vanilla JS

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- One class per file, `final readonly` for DTOs, `use function` imports
- English PHPDoc + English inline comments
- Develop directly on main

---

## Task 1: LlmCodeMapping DTO

**Files:**
- Create: `src/Dto/LlmCodeMapping.php`
- Create: `tests/Unit/Dto/LlmCodeMappingTest.php`

**Step 1: Create the DTO**

```php
// src/Dto/LlmCodeMapping.php
final readonly class LlmCodeMapping
{
    /**
     * @param string      $old  The old class, method, constant, or path
     * @param string|null $new  The replacement, or null if removed without replacement
     * @param string      $type One of: class_rename, method_rename, constant_rename,
     *                          argument_change, method_removal, class_removal,
     *                          hook_to_event, typoscript_change, tca_change, behavior_change
     */
    public function __construct(
        public string $old,
        public ?string $new,
        public string $type,
    ) {
    }
}
```

**Step 2: Write test**

```php
// tests/Unit/Dto/LlmCodeMappingTest.php
#[CoversClass(LlmCodeMapping::class)]
final class LlmCodeMappingTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $mapping = new LlmCodeMapping(
            old: 'TYPO3\CMS\Core\OldClass',
            new: 'TYPO3\CMS\Core\NewClass',
            type: 'class_rename',
        );

        self::assertSame('TYPO3\CMS\Core\OldClass', $mapping->old);
        self::assertSame('TYPO3\CMS\Core\NewClass', $mapping->new);
        self::assertSame('class_rename', $mapping->type);
    }

    #[Test]
    public function constructAllowsNullNew(): void
    {
        $mapping = new LlmCodeMapping(
            old: 'TYPO3\CMS\Core\Removed::method',
            new: null,
            type: 'method_removal',
        );

        self::assertNull($mapping->new);
    }
}
```

**Step 3:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 4: Commit**

```
Add LlmCodeMapping DTO for structured LLM code mappings
```

---

## Task 2: LlmRectorAssessment DTO

**Files:**
- Create: `src/Dto/LlmRectorAssessment.php`
- Create: `tests/Unit/Dto/LlmRectorAssessmentTest.php`

**Step 1: Create the DTO**

```php
// src/Dto/LlmRectorAssessment.php
final readonly class LlmRectorAssessment
{
    /**
     * @param bool        $feasible Whether a Rector rule can handle this migration
     * @param string|null $ruleType Suggested Rector rule type (e.g. "RenameClassRector"), or null
     * @param string      $notes    Explanation of automation limitations or edge cases
     */
    public function __construct(
        public bool $feasible,
        public ?string $ruleType,
        public string $notes,
    ) {
    }
}
```

**Step 2: Write test**

```php
// tests/Unit/Dto/LlmRectorAssessmentTest.php
#[CoversClass(LlmRectorAssessment::class)]
final class LlmRectorAssessmentTest extends TestCase
{
    #[Test]
    public function constructSetsProperties(): void
    {
        $assessment = new LlmRectorAssessment(
            feasible: true,
            ruleType: 'RenameClassRector',
            notes: 'Straightforward 1:1 rename.',
        );

        self::assertTrue($assessment->feasible);
        self::assertSame('RenameClassRector', $assessment->ruleType);
        self::assertSame('Straightforward 1:1 rename.', $assessment->notes);
    }

    #[Test]
    public function constructAllowsNullRuleType(): void
    {
        $assessment = new LlmRectorAssessment(
            feasible: false,
            ruleType: null,
            notes: 'No automation possible.',
        );

        self::assertFalse($assessment->feasible);
        self::assertNull($assessment->ruleType);
    }
}
```

**Step 3:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 4: Commit**

```
Add LlmRectorAssessment DTO for structured Rector feasibility data
```

---

## Task 3: Extend LlmAnalysisResult with new fields

**Files:**
- Modify: `src/Dto/LlmAnalysisResult.php`
- Modify: `tests/Unit/Dto/LlmAnalysisResultTest.php` (if exists, otherwise test via service tests)

**Step 1: Add codeMappings and rectorAssessment properties**

```php
// src/Dto/LlmAnalysisResult.php — add to constructor
    /** @var list<LlmCodeMapping> */
    public array $codeMappings,
    public ?LlmRectorAssessment $rectorAssessment,
```

Full constructor will be:

```php
public function __construct(
    public string $filename,
    public string $modelId,
    public string $promptVersion,
    public int $score,
    public AutomationGrade $automationGrade,
    public string $summary,
    /** @var list<string> */
    public array $migrationSteps,
    /** @var list<string> */
    public array $affectedAreas,
    /** @var list<LlmCodeMapping> */
    public array $codeMappings,
    public ?LlmRectorAssessment $rectorAssessment,
    public int $tokensInput,
    public int $tokensOutput,
    public int $durationMs,
    public string $createdAt,
) {
}
```

**Step 2: Fix ALL existing callers** — every `new LlmAnalysisResult(...)` call needs the two new parameters. Search with: `grep -rn 'new LlmAnalysisResult' src/ tests/`

Known callers:
- `src/Service/LlmAnalysisService.php` — `parseResponse()` method
- `src/Repository/LlmResultRepository.php` — `hydrate()` method
- `tests/Unit/Service/LlmAnalysisServiceTest.php` — test data construction

Add `codeMappings: [], rectorAssessment: null` to each caller temporarily. The real parsing comes in Task 5.

**Step 3:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 4: Commit**

```
Extend LlmAnalysisResult with codeMappings and rectorAssessment fields
```

---

## Task 4: DB schema migration — add columns

**Files:**
- Modify: `src/Repository/LlmResultRepository.php`

**Step 1: Add columns to CREATE TABLE**

Add after `affected_areas TEXT NOT NULL`:

```sql
code_mappings TEXT NOT NULL DEFAULT '[]',
rector_assessment TEXT DEFAULT NULL,
```

**Step 2: Add migration in `migrateSchema()`**

After the existing `raw_response` migration, add:

```php
// Add code_mappings and rector_assessment columns for enhanced prompt results
$columnNames = array_column($columns, 'name');

if (!in_array('code_mappings', $columnNames, true)) {
    $this->pdo->exec("ALTER TABLE llm_analysis_results ADD COLUMN code_mappings TEXT NOT NULL DEFAULT '[]'");
}

if (!in_array('rector_assessment', $columnNames, true)) {
    $this->pdo->exec('ALTER TABLE llm_analysis_results ADD COLUMN rector_assessment TEXT DEFAULT NULL');
}
```

**Step 3: Update `save()` — add the two new columns to INSERT**

Add to the column list and VALUES:
- `code_mappings` → `json_encode(array_map(...))` serializing each `LlmCodeMapping`
- `rector_assessment` → `json_encode(...)` or null

```php
'mappings'   => json_encode(
    array_map(
        static fn (LlmCodeMapping $m): array => ['old' => $m->old, 'new' => $m->new, 'type' => $m->type],
        $result->codeMappings,
    ),
    JSON_THROW_ON_ERROR,
),
'rector' => $result->rectorAssessment !== null
    ? json_encode([
        'feasible'  => $result->rectorAssessment->feasible,
        'rule_type' => $result->rectorAssessment->ruleType,
        'notes'     => $result->rectorAssessment->notes,
    ], JSON_THROW_ON_ERROR)
    : null,
```

**Step 4: Update `hydrate()` — deserialize the new columns**

```php
/** @var list<array{old: string, new: string|null, type: string}> $rawMappings */
$rawMappings    = json_decode($row['code_mappings'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
$codeMappings   = array_map(
    static fn (array $m): LlmCodeMapping => new LlmCodeMapping($m['old'], $m['new'] ?? null, $m['type'] ?? 'behavior_change'),
    $rawMappings,
);

$rectorAssessment = null;
if (isset($row['rector_assessment']) && $row['rector_assessment'] !== '') {
    /** @var array{feasible: bool, rule_type: string|null, notes: string} $rawAssessment */
    $rawAssessment    = json_decode($row['rector_assessment'], true, 512, JSON_THROW_ON_ERROR);
    $rectorAssessment = new LlmRectorAssessment(
        $rawAssessment['feasible'] ?? false,
        $rawAssessment['rule_type'] ?? null,
        $rawAssessment['notes'] ?? '',
    );
}
```

Pass both to the `LlmAnalysisResult` constructor.

**Step 5:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 6: Commit**

```
Add code_mappings and rector_assessment columns with schema migration
```

---

## Task 5: Update prompt and response parsing

**Files:**
- Modify: `src/Service/LlmConfigurationService.php` — `getDefaultPrompt()`
- Modify: `src/Service/LlmAnalysisService.php` — `parseResponse()`

**Step 1: Replace `getDefaultPrompt()` with the enhanced prompt**

Use the full prompt text from the design discussion (see conversation). Key additions:
- `code_mappings` array with old/new/type
- `rector_assessment` object with feasible/rule_type/notes
- Stricter format instructions
- More affected_areas options

**Step 2: Update `parseResponse()` to extract new fields**

```php
// Parse code_mappings
$rawMappings  = $data['code_mappings'] ?? [];
$codeMappings = array_map(
    static fn (array $m): LlmCodeMapping => new LlmCodeMapping(
        $m['old'] ?? '',
        $m['new'] ?? null,
        $m['type'] ?? 'behavior_change',
    ),
    array_filter($rawMappings, 'is_array'),
);

// Parse rector_assessment
$rectorAssessment = null;
$rawAssessment    = $data['rector_assessment'] ?? null;

if (is_array($rawAssessment)) {
    $rectorAssessment = new LlmRectorAssessment(
        feasible: (bool) ($rawAssessment['feasible'] ?? false),
        ruleType: $rawAssessment['rule_type'] ?? null,
        notes: (string) ($rawAssessment['notes'] ?? ''),
    );
}
```

Pass both to the `LlmAnalysisResult` constructor.

**Step 3:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 4: Commit**

```
Update analysis prompt with code mappings and Rector assessment fields
```

---

## Task 6: Update controller JSON response

**Files:**
- Modify: `src/Controller/LlmController.php` — `analyzeSingle()`

**Step 1: Add new fields to JSON response**

After `'affectedAreas'` add:

```php
'codeMappings' => array_map(
    static fn (LlmCodeMapping $m): array => [
        'old'  => $m->old,
        'new'  => $m->new,
        'type' => $m->type,
    ],
    $result->codeMappings,
),
'rectorAssessment' => $result->rectorAssessment !== null ? [
    'feasible' => $result->rectorAssessment->feasible,
    'ruleType' => $result->rectorAssessment->ruleType,
    'notes'    => $result->rectorAssessment->notes,
] : null,
```

Add `use App\Dto\LlmCodeMapping;` import.

**Step 2:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 3: Commit**

```
Include code mappings and Rector assessment in analysis JSON response
```

---

## Task 7: Update Twig template

**Files:**
- Modify: `templates/deprecation/detail.html.twig`

**Step 1: Add code mappings display** — after the migration steps section (line ~251), before affected areas:

```twig
{% if llmResult.codeMappings|length > 0 %}
    <h6 class="small fw-bold text-muted mt-3 mb-1">Code-Mappings</h6>
    <div class="small mb-2">
        {% for mapping in llmResult.codeMappings %}
            <div class="d-flex align-items-start gap-2 mb-1">
                <code class="text-danger text-break">{{ mapping.old }}</code>
                <i class="bi bi-arrow-right text-muted flex-shrink-0"></i>
                {% if mapping.new %}
                    <code class="text-success text-break">{{ mapping.new }}</code>
                {% else %}
                    <span class="text-muted fst-italic">entfernt</span>
                {% endif %}
                <span class="badge bg-light text-muted border ms-auto flex-shrink-0">{{ mapping.type }}</span>
            </div>
        {% endfor %}
    </div>
{% endif %}
```

**Step 2: Add Rector assessment display** — after affected areas:

```twig
{% if llmResult.rectorAssessment is not null %}
    <div class="mt-2 small border-top pt-2">
        <i class="bi bi-gear me-1"></i>
        <strong>Rector:</strong>
        {% if llmResult.rectorAssessment.feasible %}
            <span class="text-success">Automatisierbar</span>
            {% if llmResult.rectorAssessment.ruleType %}
                <span class="text-muted">via {{ llmResult.rectorAssessment.ruleType }}</span>
            {% endif %}
        {% else %}
            <span class="text-danger">Nicht automatisierbar</span>
        {% endif %}
        {% if llmResult.rectorAssessment.notes %}
            <br><span class="text-muted">{{ llmResult.rectorAssessment.notes }}</span>
        {% endif %}
    </div>
{% endif %}
```

**Step 3:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 4: Commit**

```
Display code mappings and Rector assessment in detail view
```

---

## Task 8: Update JavaScript for AJAX analysis

**Files:**
- Modify: `public/js/llm-analysis.js`

**Step 1: Add code mappings rendering in `renderResult()`**

After the `migrationSteps` section, add similar rendering for `codeMappings`:

```javascript
if (data.codeMappings && data.codeMappings.length > 0) {
    html += '<h6 class="small fw-bold text-muted mt-3 mb-1">Code-Mappings</h6>';
    html += '<div class="small mb-2">';
    data.codeMappings.forEach(function (mapping) {
        html += '<div class="d-flex align-items-start gap-2 mb-1">';
        html += '<code class="text-danger text-break">' + escapeHtml(mapping.old) + '</code>';
        html += '<i class="bi bi-arrow-right text-muted flex-shrink-0"></i>';
        if (mapping.new) {
            html += '<code class="text-success text-break">' + escapeHtml(mapping.new) + '</code>';
        } else {
            html += '<span class="text-muted fst-italic">entfernt</span>';
        }
        html += '<span class="badge bg-light text-muted border ms-auto flex-shrink-0">' + escapeHtml(mapping.type) + '</span>';
        html += '</div>';
    });
    html += '</div>';
}
```

**Step 2: Add Rector assessment rendering**

```javascript
if (data.rectorAssessment) {
    html += '<div class="mt-2 small border-top pt-2">';
    html += '<i class="bi bi-gear me-1"></i><strong>Rector:</strong> ';
    if (data.rectorAssessment.feasible) {
        html += '<span class="text-success">Automatisierbar</span>';
        if (data.rectorAssessment.ruleType) {
            html += ' <span class="text-muted">via ' + escapeHtml(data.rectorAssessment.ruleType) + '</span>';
        }
    } else {
        html += '<span class="text-danger">Nicht automatisierbar</span>';
    }
    if (data.rectorAssessment.notes) {
        html += '<br><span class="text-muted">' + escapeHtml(data.rectorAssessment.notes) + '</span>';
    }
    html += '</div>';
}
```

**Step 3: Commit**

```
Render code mappings and Rector assessment in AJAX analysis response
```

---

## Task 9: Update tests

**Files:**
- Modify: `tests/Unit/Service/LlmAnalysisServiceTest.php`
- Modify: `tests/Unit/Service/LlmConfigurationServiceTest.php`

**Step 1: Update `LlmAnalysisServiceTest::analyzeCallsLlmWhenNoCachedResult`**

Add `code_mappings` and `rector_assessment` to the mock JSON response:

```php
$innerJson = json_encode([
    'score'              => 3,
    'automation_grade'   => 'partial',
    'summary'            => 'Method signature changed',
    'migration_steps'    => ['Update call sites'],
    'affected_areas'     => ['PHP', 'TCA'],
    'code_mappings'      => [
        ['old' => 'OldClass::method', 'new' => 'NewClass::method', 'type' => 'method_rename'],
    ],
    'rector_assessment'  => [
        'feasible'  => true,
        'rule_type' => 'RenameMethodRector',
        'notes'     => 'Straightforward rename.',
    ],
], JSON_THROW_ON_ERROR);
```

Add assertions for the new fields:

```php
self::assertCount(1, $result->codeMappings);
self::assertSame('OldClass::method', $result->codeMappings[0]->old);
self::assertSame('NewClass::method', $result->codeMappings[0]->new);
self::assertSame('method_rename', $result->codeMappings[0]->type);
self::assertNotNull($result->rectorAssessment);
self::assertTrue($result->rectorAssessment->feasible);
self::assertSame('RenameMethodRector', $result->rectorAssessment->ruleType);
```

**Step 2: Update `getDefaultPromptReturnsNonEmptyString` test**

Add assertions for new prompt content:

```php
self::assertStringContainsString('code_mappings', $service->getDefaultPrompt());
self::assertStringContainsString('rector_assessment', $service->getDefaultPrompt());
```

**Step 3: Update all `new LlmAnalysisResult(...)` calls in tests**

Search all test files for `new LlmAnalysisResult`. Add `codeMappings: [], rectorAssessment: null` to each.

**Step 4:** `composer ci:cgl && composer ci:rector && composer ci:test`

**Step 5: Commit**

```
Add tests for code mappings and Rector assessment parsing
```

---

## Task 10: Final cleanup and review

**Step 1:** `composer ci:cgl && composer ci:rector && composer ci:test` — all green
**Step 2:** Code review all changed files
**Step 3:** Verify by triggering a re-analysis in the UI (the prompt version hash will have changed, so all documents count as unanalyzed)

---

## Zusammenfassung

| Task | Komponente | Dateien |
|------|-----------|--------|
| 1 | LlmCodeMapping DTO | `src/Dto/LlmCodeMapping.php` |
| 2 | LlmRectorAssessment DTO | `src/Dto/LlmRectorAssessment.php` |
| 3 | Extend LlmAnalysisResult | `src/Dto/LlmAnalysisResult.php`, all callers |
| 4 | DB schema migration | `src/Repository/LlmResultRepository.php` |
| 5 | Prompt + response parsing | `LlmConfigurationService.php`, `LlmAnalysisService.php` |
| 6 | Controller JSON | `src/Controller/LlmController.php` |
| 7 | Twig template | `templates/deprecation/detail.html.twig` |
| 8 | JavaScript | `public/js/llm-analysis.js` |
| 9 | Tests | `tests/Unit/Service/*.php` |
| 10 | Cleanup + review | Alle Dateien |
