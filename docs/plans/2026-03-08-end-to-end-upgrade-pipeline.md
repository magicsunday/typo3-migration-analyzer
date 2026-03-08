# End-to-End Upgrade Pipeline Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Improve pattern recognition from 1.2% to ~30%+, then connect scan findings to an action plan with Rector-Config-Export.

**Architecture:** Extend CodeReference to accept non-FQCN values with resolution confidence. Add new text patterns to MigrationMappingExtractor and expand its scope to Description sections. Build ActionPlanGenerator that correlates scan findings with documents/mappings/Rector-rules. Add two-view UI (by automation grade + by file) with combined Rector-Config-Export.

**Tech Stack:** PHP 8.4, Symfony 7.4, Twig, Bootstrap 5.3, PHPUnit 12

**Design doc:** `docs/plans/2026-03-08-end-to-end-upgrade-pipeline-design.md`

---

## Task 1: Extend CodeReferenceType enum with new cases

**Files:**
- Modify: `src/Dto/CodeReferenceType.php`
- Modify: `tests/Unit/Dto/CodeReferenceTest.php`

**Context:** Currently the enum has 5 cases (ClassName, InstanceMethod, StaticMethod, Property, ClassConstant). All require FQCN. We need new cases for non-FQCN values that `fromPhpRole()` currently discards as `null`.

**Step 1: Add new enum cases**

Add these cases to `src/Dto/CodeReferenceType.php`:

```php
enum CodeReferenceType: string
{
    case ClassName          = 'class_name';
    case InstanceMethod     = 'instance_method';
    case StaticMethod       = 'static_method';
    case Property           = 'property';
    case ClassConstant      = 'class_constant';
    case ShortClassName     = 'short_class_name';
    case UnqualifiedMethod  = 'unqualified_method';
    case ConfigKey          = 'config_key';
}
```

**Step 2: Run tests to verify nothing breaks**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS (enum extension is backwards-compatible, existing cases unchanged)

**Step 3: Commit**

```bash
git add src/Dto/CodeReferenceType.php
git commit -m "Add ShortClassName, UnqualifiedMethod, ConfigKey to CodeReferenceType"
```

---

## Task 2: Add resolutionConfidence to CodeReference

**Files:**
- Modify: `src/Dto/CodeReference.php`
- Modify: `tests/Unit/Dto/CodeReferenceTest.php`

**Context:** CodeReference needs a confidence float to indicate how reliably the reference was parsed. FQCN = 1.0, short class = 0.7, method only = 0.5, etc. The readonly class gets a new constructor parameter with default 1.0 for backward compatibility.

**Step 1: Write the failing test**

Add to `tests/Unit/Dto/CodeReferenceTest.php`:

```php
#[Test]
public function fromPhpRoleSetsFullConfidenceForFqcn(): void
{
    $ref = CodeReference::fromPhpRole('TYPO3\CMS\Core\Utility\GeneralUtility');

    self::assertNotNull($ref);
    self::assertSame(1.0, $ref->resolutionConfidence);
}
```

**Step 2: Run test to verify it fails**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Dto/CodeReferenceTest.php --filter=fromPhpRoleSetsFullConfidenceForFqcn`
Expected: FAIL — property `resolutionConfidence` does not exist

**Step 3: Add the property to CodeReference**

In `src/Dto/CodeReference.php`, add the constructor parameter:

```php
public function __construct(
    public string $className,
    public ?string $member,
    public CodeReferenceType $type,
    public float $resolutionConfidence = 1.0,
) {
}
```

All existing `new CodeReference(...)` calls pass exactly 3 args — the default `1.0` keeps them working.

**Step 4: Run test to verify it passes**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Dto/CodeReferenceTest.php --filter=fromPhpRoleSetsFullConfidenceForFqcn`
Expected: PASS

**Step 5: Run full test suite**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Dto/CodeReference.php tests/Unit/Dto/CodeReferenceTest.php
git commit -m "Add resolutionConfidence property to CodeReference"
```

---

## Task 3: Extend CodeReference::fromPhpRole() to accept non-FQCN values

**Files:**
- Modify: `src/Dto/CodeReference.php:43-55`
- Modify: `tests/Unit/Dto/CodeReferenceTest.php`

**Context:** Currently `fromPhpRole()` returns `null` when there is no namespace separator (`\`). We need it to accept short class names, bare method names, properties, constants, and config keys. It should still return `null` for PHP keywords/literals (`true`, `false`, `null`, `array`, `mixed`, `string`, `int`, `bool`, `void`, `self`, `static`, `parent`, `@internal`).

**Step 1: Write the failing tests**

Add to `tests/Unit/Dto/CodeReferenceTest.php`:

```php
#[Test]
public function fromPhpRoleParsesShortClassName(): void
{
    $ref = CodeReference::fromPhpRole('ConfigurationView');

    self::assertNotNull($ref);
    self::assertSame('ConfigurationView', $ref->className);
    self::assertNull($ref->member);
    self::assertSame(CodeReferenceType::ShortClassName, $ref->type);
    self::assertSame(0.7, $ref->resolutionConfidence);
}

#[Test]
public function fromPhpRoleParsesUnqualifiedMethod(): void
{
    $ref = CodeReference::fromPhpRole('getIdentifier()');

    self::assertNotNull($ref);
    self::assertSame('', $ref->className);
    self::assertSame('getIdentifier', $ref->member);
    self::assertSame(CodeReferenceType::UnqualifiedMethod, $ref->type);
    self::assertSame(0.5, $ref->resolutionConfidence);
}

#[Test]
public function fromPhpRoleParsesPropertyWithoutClass(): void
{
    $ref = CodeReference::fromPhpRole('$sourceTypes');

    self::assertNotNull($ref);
    self::assertSame('', $ref->className);
    self::assertSame('sourceTypes', $ref->member);
    self::assertSame(CodeReferenceType::Property, $ref->type);
    self::assertSame(0.6, $ref->resolutionConfidence);
}

#[Test]
public function fromPhpRoleParsesUnqualifiedConstant(): void
{
    $ref = CodeReference::fromPhpRole('SOME_CONSTANT');

    self::assertNotNull($ref);
    self::assertSame('', $ref->className);
    self::assertSame('SOME_CONSTANT', $ref->member);
    self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
    self::assertSame(0.6, $ref->resolutionConfidence);
}

#[Test]
public function fromPhpRoleParsesConfigKey(): void
{
    $ref = CodeReference::fromPhpRole('config.contentObjectExceptionHandler');

    self::assertNotNull($ref);
    self::assertSame('config.contentObjectExceptionHandler', $ref->className);
    self::assertNull($ref->member);
    self::assertSame(CodeReferenceType::ConfigKey, $ref->type);
    self::assertSame(0.4, $ref->resolutionConfidence);
}

#[Test]
public function fromPhpRoleReturnsNullForPhpKeywords(): void
{
    self::assertNull(CodeReference::fromPhpRole('true'));
    self::assertNull(CodeReference::fromPhpRole('false'));
    self::assertNull(CodeReference::fromPhpRole('null'));
    self::assertNull(CodeReference::fromPhpRole('array'));
    self::assertNull(CodeReference::fromPhpRole('mixed'));
    self::assertNull(CodeReference::fromPhpRole('string'));
    self::assertNull(CodeReference::fromPhpRole('int'));
    self::assertNull(CodeReference::fromPhpRole('void'));
    self::assertNull(CodeReference::fromPhpRole('self'));
    self::assertNull(CodeReference::fromPhpRole('static'));
    self::assertNull(CodeReference::fromPhpRole('parent'));
    self::assertNull(CodeReference::fromPhpRole('@internal'));
    self::assertNull(CodeReference::fromPhpRole('new'));
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Dto/CodeReferenceTest.php --filter="parsesShortClassName|parsesUnqualifiedMethod|parsesPropertyWithoutClass|parsesUnqualifiedConstant|parsesConfigKey|ReturnsNullForPhpKeywords"`
Expected: FAIL — most return `null` (existing behavior)

**Step 3: Rewrite the non-FQCN handling in fromPhpRole()**

Replace the early-return block in `src/Dto/CodeReference.php` (lines 52-55, the `if (!str_contains($value, '\\'))` block) with:

```php
// Must contain at least one namespace separator (2+ segments) to be a FQCN
if (!str_contains($value, '\\')) {
    return self::fromNonFqcn($value);
}
```

Add the new private static method and the ignore list:

```php
/** PHP keywords and literals that should not be treated as code references. */
private const array IGNORED_VALUES = [
    'true', 'false', 'null', 'array', 'mixed', 'string', 'int', 'float',
    'bool', 'void', 'never', 'self', 'static', 'parent', 'callable',
    'iterable', 'object', 'new', '@internal',
];

/**
 * Parse a non-FQCN `:php:` role value into a CodeReference.
 *
 * Handles short class names, bare methods, properties, constants, and config keys.
 * Returns null for PHP keywords/literals.
 */
private static function fromNonFqcn(string $value): ?self
{
    if (in_array(strtolower($value), self::IGNORED_VALUES, true)) {
        return null;
    }

    // Property: $property
    if (str_starts_with($value, '$')) {
        return new self(
            className: '',
            member: ltrim($value, '$'),
            type: CodeReferenceType::Property,
            resolutionConfidence: 0.6,
        );
    }

    // Method: methodName()
    if (str_ends_with($value, '()')) {
        return new self(
            className: '',
            member: rtrim($value, '()'),
            type: CodeReferenceType::UnqualifiedMethod,
            resolutionConfidence: 0.5,
        );
    }

    // Constant: ALL_UPPER_CASE (3+ chars, all uppercase with underscores/digits)
    if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $value) === 1) {
        return new self(
            className: '',
            member: $value,
            type: CodeReferenceType::ClassConstant,
            resolutionConfidence: 0.6,
        );
    }

    // Config key: contains dots or slashes (e.g. config.contentObjectExceptionHandler)
    if (str_contains($value, '.') || str_contains($value, '/')) {
        return new self(
            className: $value,
            member: null,
            type: CodeReferenceType::ConfigKey,
            resolutionConfidence: 0.4,
        );
    }

    // Short class name: starts with uppercase letter (e.g. ConfigurationView)
    if (preg_match('/^[A-Z][a-zA-Z0-9]+$/', $value) === 1) {
        return new self(
            className: $value,
            member: null,
            type: CodeReferenceType::ShortClassName,
            resolutionConfidence: 0.7,
        );
    }

    // Anything else: treat as unqualified method name without parens
    return new self(
        className: '',
        member: $value,
        type: CodeReferenceType::UnqualifiedMethod,
        resolutionConfidence: 0.3,
    );
}
```

Add the missing imports to the top of the file:

```php
use function in_array;
use function strtolower;
```

**Step 4: Update existing tests that relied on null returns for non-FQCN**

The existing test `fromPhpRoleReturnsNullForPlainFunctionName` tests `fromPhpRole('file')` returning `null`. With the new logic, `'file'` will match the fallback (unqualified method, confidence 0.3). Update it:

```php
#[Test]
public function fromPhpRoleParsesPlainFunctionNameAsUnqualifiedMethod(): void
{
    $ref = CodeReference::fromPhpRole('file');

    self::assertNotNull($ref);
    self::assertSame(CodeReferenceType::UnqualifiedMethod, $ref->type);
    self::assertSame(0.3, $ref->resolutionConfidence);
}
```

The test `fromPhpRoleReturnsNullForGlobalConstant` tests `fromPhpRole('E_USER_DEPRECATED')`. This now becomes a ClassConstant:

```php
#[Test]
public function fromPhpRoleParsesGlobalConstantAsClassConstant(): void
{
    $ref = CodeReference::fromPhpRole('E_USER_DEPRECATED');

    self::assertNotNull($ref);
    self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
    self::assertSame(0.6, $ref->resolutionConfidence);
}
```

The test `fromPhpRoleReturnsNullForSingleSegmentNamespace` tests `fromPhpRole('GeneralUtility')`. This now becomes a ShortClassName:

```php
#[Test]
public function fromPhpRoleParsesShortClassNameForSingleSegment(): void
{
    $ref = CodeReference::fromPhpRole('GeneralUtility');

    self::assertNotNull($ref);
    self::assertSame('GeneralUtility', $ref->className);
    self::assertSame(CodeReferenceType::ShortClassName, $ref->type);
    self::assertSame(0.7, $ref->resolutionConfidence);
}
```

**Step 5: Run all tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Dto/CodeReference.php src/Dto/CodeReferenceType.php tests/Unit/Dto/CodeReferenceTest.php
git commit -m "Extend CodeReference::fromPhpRole() to accept non-FQCN values with resolution confidence"
```

---

## Task 4: Update downstream consumers to handle new CodeReference types

**Files:**
- Modify: `src/Generator/MatcherConfigGenerator.php`
- Modify: `src/Generator/RectorRuleGenerator.php`
- Modify: `tests/Unit/Generator/MatcherConfigGeneratorTest.php`
- Modify: `tests/Unit/Generator/RectorRuleGeneratorTest.php`

**Context:** MatcherConfigGenerator and RectorRuleGenerator currently use `match` expressions on `CodeReferenceType` values. They need a `default` case or explicit handling for the new types. Both should skip non-FQCN references (they need FQCNs for valid output) — they should filter on `resolutionConfidence >= 0.9` or check for FQCN types.

**Step 1: Write the failing test for MatcherConfigGenerator**

Add to `tests/Unit/Generator/MatcherConfigGeneratorTest.php`:

```php
#[Test]
public function generateSkipsNonFqcnReferences(): void
{
    $doc = $this->createDocument(
        codeReferences: [
            new CodeReference('', 'oldMethod', CodeReferenceType::UnqualifiedMethod, 0.5),
            new CodeReference('ConfigView', null, CodeReferenceType::ShortClassName, 0.7),
        ],
    );

    $entries = $this->generator->generate($doc);

    self::assertSame([], $entries);
}
```

**Step 2: Run test to verify it fails**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Generator/MatcherConfigGeneratorTest.php --filter=generateSkipsNonFqcnReferences`
Expected: FAIL — likely throws on unhandled enum case in `resolveMatcherType`

**Step 3: Add FQCN filter to MatcherConfigGenerator**

In `src/Generator/MatcherConfigGenerator.php`, in the `generate()` method, add a confidence filter before processing each code reference. Add a helper method:

```php
/**
 * Whether the reference is fully qualified and suitable for matcher generation.
 */
private function isFullyQualified(CodeReference $ref): bool
{
    return $ref->resolutionConfidence >= 0.9;
}
```

Use it to filter in `generate()`:

```php
foreach ($document->codeReferences as $ref) {
    if (!$this->isFullyQualified($ref)) {
        continue;
    }
    // ... existing logic
}
```

**Step 4: Add FQCN filter to RectorRuleGenerator**

Similarly in `src/Generator/RectorRuleGenerator.php`, the `generate()` method creates skeleton rules for code references without mappings. Filter out non-FQCN references:

```php
// 2. Generate skeleton rules for code references without mappings
foreach ($document->codeReferences as $ref) {
    if ($ref->resolutionConfidence < 0.9) {
        continue;
    }

    $key = $this->buildRefKey($ref);
    // ... rest unchanged
}
```

Also in the skeleton renderer, the `NODE_TYPE_MAP` needs entries for new types (or the code should handle missing keys gracefully). Add a guard at the beginning of `renderSkeleton()`:

```php
if (!isset(self::NODE_TYPE_MAP[$rule->source->type->value])) {
    return '';
}
```

**Step 5: Run all tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Generator/MatcherConfigGenerator.php src/Generator/RectorRuleGenerator.php tests/Unit/Generator/MatcherConfigGeneratorTest.php tests/Unit/Generator/RectorRuleGeneratorTest.php
git commit -m "Filter non-FQCN references in Matcher and Rector generators"
```

---

## Task 5: Add new text patterns to MigrationMappingExtractor

**Files:**
- Modify: `src/Analyzer/MigrationMappingExtractor.php:34-43`
- Modify: `tests/Unit/Analyzer/MigrationMappingExtractorTest.php`

**Context:** Currently 4 patterns: "Replace...with", "renamed to", "Use...instead of", "Migrate...to". We need to add: "has been moved to", "has been changed to", "replaced by / has been replaced", "can be replaced by/with", "should be replaced by", bare `:php:`Old` to :php:`New`` connector.

**Step 1: Write the failing tests**

Add to `tests/Unit/Analyzer/MigrationMappingExtractorTest.php`:

```php
#[Test]
public function extractMovedToPattern(): void
{
    $text = ':php:`\TYPO3\CMS\Core\OldClass` has been moved to :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
    self::assertSame(1.0, $mappings[0]->confidence);
}

#[Test]
public function extractChangedToPattern(): void
{
    $text = ':php:`\TYPO3\CMS\Core\OldClass` has been changed to :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
}

#[Test]
public function extractReplacedByPattern(): void
{
    $text = ':php:`\TYPO3\CMS\Core\OldClass` has been replaced by :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
}

#[Test]
public function extractCanBeReplacedByPattern(): void
{
    $text = ':php:`\TYPO3\CMS\Core\OldClass` can be replaced by :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
}

#[Test]
public function extractShouldBeReplacedByPattern(): void
{
    $text = ':php:`\TYPO3\CMS\Core\OldClass` should be replaced by :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
}

#[Test]
public function extractBareToConnectorPattern(): void
{
    $text = '* :php:`\TYPO3\CMS\Lowlevel\View\ConfigurationView` to :php:`\TYPO3\CMS\Lowlevel\Controller\ConfigurationController`';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Lowlevel\View\ConfigurationView', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Lowlevel\Controller\ConfigurationController', $mappings[0]->target->className);
}

#[Test]
public function extractIsNowAvailableViaPattern(): void
{
    $text = ':php:`\TYPO3\CMS\Core\OldClass` is now available via :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
}

#[Test]
public function extractNonFqcnMappingWithReducedConfidence(): void
{
    $text = 'Rename :php:`pi_list_browseresults()` to :php:`pi_list_browseResults()`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('pi_list_browseresults', $mappings[0]->source->member);
    self::assertSame('pi_list_browseResults', $mappings[0]->target->member);
    // Confidence = pattern 1.0 × min(source 0.5, target 0.5) = 0.5
    self::assertLessThan(1.0, $mappings[0]->confidence);
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Analyzer/MigrationMappingExtractorTest.php --filter="extractMovedToPattern|extractChangedToPattern|extractReplacedByPattern|extractCanBeReplacedByPattern|extractShouldBeReplacedByPattern|extractBareToConnectorPattern|extractIsNowAvailableViaPattern|extractNonFqcnMappingWithReducedConfidence"`
Expected: FAIL

**Step 3: Add new patterns to MigrationMappingExtractor**

In `src/Analyzer/MigrationMappingExtractor.php`, extend the `MAPPING_PATTERNS` array:

```php
private const array MAPPING_PATTERNS = [
    // "Replace :php:`Old` with/by :php:`New`"
    ['/\b[Rr]eplace\b.*?:php:`([^`]+)`.*?\b(?:with|by)\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
    // ":php:`Old` has been/was renamed to :php:`New`"
    ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+renamed\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
    // "Use :php:`New` instead of :php:`Old`" (note: reversed order)
    ['/\b[Uu]se\b.*?:php:`([^`]+)`.*?\binstead\s+of\b.*?:php:`([^`]+)`/s', 2, 1, 0.9],
    // "Migrate [from] :php:`Old` to :php:`New`"
    ['/\b[Mm]igrate\b.*?(?:from\s+)?:php:`([^`]+)`.*?\bto\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
    // ":php:`Old` has been/was moved to :php:`New`"
    ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+moved\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
    // ":php:`Old` has been/was changed to :php:`New`"
    ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+changed\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 0.9],
    // ":php:`Old` (has been|was|should be|can be) replaced (by|with) :php:`New`"
    ['/:php:`([^`]+)`.*?\b(?:has been|was|should be|can be)\s+replaced\s+(?:by|with)\b.*?:php:`([^`]+)`/s', 1, 2, 0.9],
    // ":php:`Old` is now available via :php:`New`"
    ['/:php:`([^`]+)`.*?\bis\s+now\s+available\s+via\b.*?:php:`([^`]+)`/s', 1, 2, 0.8],
    // Bare ":php:`Old` to :php:`New`" (no keyword prefix — lowest priority)
    ['/:php:`([^`]+)`\s+to\s+:php:`([^`]+)`/', 1, 2, 0.9],
];
```

**Step 4: Incorporate resolution confidence into mapping confidence**

Modify the mapping creation in the `extract()` method to multiply pattern confidence by the minimum resolution confidence of source and target:

```php
foreach ($matches as $match) {
    $source = CodeReference::fromPhpRole($match[$sourceGroup]);
    $target = CodeReference::fromPhpRole($match[$targetGroup]);

    if (!$source instanceof CodeReference) {
        continue;
    }

    if (!$target instanceof CodeReference) {
        continue;
    }

    // Deduplicate by className + member
    $key = $source->className . '::' . ($source->member ?? '')
        . '->' . $target->className . '::' . ($target->member ?? '');

    if (isset($seen[$key])) {
        continue;
    }

    // Effective confidence = pattern confidence × min(source, target) resolution confidence
    $effectiveConfidence = $confidence * min(
        $source->resolutionConfidence,
        $target->resolutionConfidence,
    );

    $seen[$key] = true;
    $mappings[] = new MigrationMapping($source, $target, $effectiveConfidence);
}
```

Add `use function min;` import.

**Step 5: Update existing test `extractSkipsNonFqcnReferences`**

This test used `Replace :php:\`oldFunction()\` with :php:\`newFunction()\`` and expected empty result. Now it will find a mapping (with reduced confidence). Update:

```php
#[Test]
public function extractNonFqcnReferencesWithReducedConfidence(): void
{
    $text = 'Replace :php:`oldFunction()` with :php:`newFunction()`.';

    $mappings = $this->extractor->extract($text);

    self::assertCount(1, $mappings);
    self::assertSame('oldFunction', $mappings[0]->source->member);
    self::assertSame('newFunction', $mappings[0]->target->member);
    self::assertSame(0.5, $mappings[0]->confidence); // 1.0 × min(0.5, 0.5)
}
```

**Step 6: Run all tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 7: Commit**

```bash
git add src/Analyzer/MigrationMappingExtractor.php tests/Unit/Analyzer/MigrationMappingExtractorTest.php
git commit -m "Add 5 new mapping patterns and integrate resolution confidence"
```

---

## Task 6: Expand MigrationMappingExtractor to scan Description section

**Files:**
- Modify: `src/Analyzer/MigrationMappingExtractor.php`
- Modify: `src/Analyzer/ComplexityScorer.php:89`
- Modify: `src/Generator/RectorRuleGenerator.php:75`
- Modify: `tests/Unit/Analyzer/MigrationMappingExtractorTest.php`
- Modify: `tests/Unit/Analyzer/ComplexityScorerTest.php`

**Context:** The extractor currently only receives migration text. Issue #82744 has its rename mappings in the Description section, not in Migration. The extract method signature changes from `extract(?string $migrationText)` to `extract(?string $migrationText, ?string $descriptionText = null)`.

**Step 1: Write the failing test**

Add to `tests/Unit/Analyzer/MigrationMappingExtractorTest.php`:

```php
#[Test]
public function extractFindsPatternInDescription(): void
{
    $migration   = 'Use new class names instead.';
    $description = '* :php:`\TYPO3\CMS\Lowlevel\View\ConfigurationView` to :php:`\TYPO3\CMS\Lowlevel\Controller\ConfigurationController`';

    $mappings = $this->extractor->extract($migration, $description);

    self::assertCount(1, $mappings);
    self::assertSame('TYPO3\CMS\Lowlevel\View\ConfigurationView', $mappings[0]->source->className);
    self::assertSame('TYPO3\CMS\Lowlevel\Controller\ConfigurationController', $mappings[0]->target->className);
}

#[Test]
public function extractDeduplicatesAcrossMigrationAndDescription(): void
{
    $migration   = 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.';
    $description = ':php:`\TYPO3\CMS\Core\OldClass` has been renamed to :php:`\TYPO3\CMS\Core\NewClass`.';

    $mappings = $this->extractor->extract($migration, $description);

    self::assertCount(1, $mappings);
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Analyzer/MigrationMappingExtractorTest.php --filter="extractFindsPatternInDescription|extractDeduplicatesAcrossMigrationAndDescription"`
Expected: FAIL — extract() doesn't accept second parameter yet

**Step 3: Update extract() signature and logic**

In `src/Analyzer/MigrationMappingExtractor.php`, change the `extract` method:

```php
/**
 * Extract old->new API mappings from RST migration and description text.
 *
 * Scans both sections for patterns (migration takes precedence for deduplication).
 *
 * @return list<MigrationMapping>
 */
public function extract(?string $migrationText, ?string $descriptionText = null): array
{
    $mappings = [];
    $seen     = [];

    // Scan migration first (higher priority), then description
    foreach ([$migrationText, $descriptionText] as $text) {
        if ($text === null || $text === '') {
            continue;
        }

        foreach (self::MAPPING_PATTERNS as [$pattern, $sourceGroup, $targetGroup, $confidence]) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $source = CodeReference::fromPhpRole($match[$sourceGroup]);
                $target = CodeReference::fromPhpRole($match[$targetGroup]);

                if (!$source instanceof CodeReference) {
                    continue;
                }

                if (!$target instanceof CodeReference) {
                    continue;
                }

                $key = $source->className . '::' . ($source->member ?? '')
                    . '->' . $target->className . '::' . ($target->member ?? '');

                if (isset($seen[$key])) {
                    continue;
                }

                $effectiveConfidence = $confidence * min(
                    $source->resolutionConfidence,
                    $target->resolutionConfidence,
                );

                $seen[$key] = true;
                $mappings[] = new MigrationMapping($source, $target, $effectiveConfidence);
            }
        }
    }

    return $mappings;
}
```

**Step 4: Update callers to pass description**

In `src/Analyzer/ComplexityScorer.php` line 89:

```php
$mappings = $this->extractor->extract($document->migration, $document->description);
```

In `src/Generator/RectorRuleGenerator.php` line 75:

```php
$mappings = $this->extractor->extract($document->migration, $document->description);
```

**Step 5: Run all tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Analyzer/MigrationMappingExtractor.php src/Analyzer/ComplexityScorer.php src/Generator/RectorRuleGenerator.php tests/Unit/Analyzer/MigrationMappingExtractorTest.php
git commit -m "Expand MigrationMappingExtractor to scan both migration and description sections"
```

---

## Task 7: Create AutomationGrade enum and ActionItem DTO

**Files:**
- Create: `src/Dto/AutomationGrade.php`
- Create: `src/Dto/ActionItem.php`
- Create: `tests/Unit/Dto/ActionItemTest.php`

**Context:** New DTOs for the action plan. `AutomationGrade` classifies how automatable a finding is. `ActionItem` links a scan finding to its RST document, mappings, complexity score, and Rector rules.

**Step 1: Create AutomationGrade enum**

Create `src/Dto/AutomationGrade.php`:

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
 * Classifies how automatable a migration action is.
 */
enum AutomationGrade: string
{
    /** Rector can handle the migration completely. */
    case Full = 'full';

    /** Rector handles part of the migration; manual work remains. */
    case Partial = 'partial';

    /** No Rector support; entirely manual migration. */
    case Manual = 'manual';
}
```

**Step 2: Create ActionItem DTO**

Create `src/Dto/ActionItem.php`:

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
 * A single action in the migration plan, linking a document to scan findings
 * with matched Rector rules and automation assessment.
 */
final readonly class ActionItem
{
    /**
     * @param list<array{file: string, finding: ScanFinding}> $findings    Affected files and findings from scan
     * @param list<MigrationMapping>                          $mappings    Detected old->new mappings
     * @param list<RectorRule>                                $rectorRules Matched Rector rules
     */
    public function __construct(
        public RstDocument $document,
        public ComplexityScore $complexity,
        public array $findings,
        public array $mappings,
        public array $rectorRules,
        public AutomationGrade $automationGrade,
    ) {
    }

    /**
     * Returns the number of affected files.
     */
    public function affectedFileCount(): int
    {
        $files = [];

        foreach ($this->findings as $entry) {
            $files[$entry['file']] = true;
        }

        return count($files);
    }
}
```

Add import: `use function count;`

**Step 3: Write tests for ActionItem**

Create `tests/Unit/Dto/ActionItemTest.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ActionItem;
use App\Dto\AutomationGrade;
use App\Dto\ComplexityScore;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanFinding;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionItem::class)]
final class ActionItemTest extends TestCase
{
    #[Test]
    public function affectedFileCountDeduplicatesFiles(): void
    {
        $finding1 = new ScanFinding(10, 'msg1', 'strong', 'code1', ['Doc.rst']);
        $finding2 = new ScanFinding(20, 'msg2', 'strong', 'code2', ['Doc.rst']);
        $finding3 = new ScanFinding(5, 'msg3', 'weak', 'code3', ['Doc.rst']);

        $item = new ActionItem(
            document: $this->createDocument(),
            complexity: new ComplexityScore(1, 'test', true),
            findings: [
                ['file' => 'src/Foo.php', 'finding' => $finding1],
                ['file' => 'src/Foo.php', 'finding' => $finding2],
                ['file' => 'src/Bar.php', 'finding' => $finding3],
            ],
            mappings: [],
            rectorRules: [],
            automationGrade: AutomationGrade::Full,
        );

        self::assertSame(2, $item->affectedFileCount());
    }

    private function createDocument(): RstDocument
    {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 99999,
            title: 'Test',
            version: '13.0',
            description: '',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-99999-Test.rst',
        );
    }
}
```

**Step 4: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Dto/AutomationGrade.php src/Dto/ActionItem.php tests/Unit/Dto/ActionItemTest.php
git commit -m "Add AutomationGrade enum and ActionItem DTO"
```

---

## Task 8: Create ActionPlanSummary DTO

**Files:**
- Create: `src/Dto/ActionPlanSummary.php`

**Context:** Summary statistics for the action plan: counts by automation grade, total findings, total affected files.

**Step 1: Create the DTO**

Create `src/Dto/ActionPlanSummary.php`:

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
 * Summary statistics for an action plan.
 */
final readonly class ActionPlanSummary
{
    public function __construct(
        public int $totalItems,
        public int $totalFindings,
        public int $fullCount,
        public int $partialCount,
        public int $manualCount,
    ) {
    }
}
```

**Step 2: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 3: Commit**

```bash
git add src/Dto/ActionPlanSummary.php
git commit -m "Add ActionPlanSummary DTO"
```

---

## Task 9: Create ActionPlan DTO

**Files:**
- Create: `src/Dto/ActionPlan.php`
- Create: `tests/Unit/Dto/ActionPlanTest.php`

**Context:** Top-level DTO wrapping the list of ActionItems with summary and helper methods.

**Step 1: Create the DTO**

Create `src/Dto/ActionPlan.php`:

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

use function array_filter;
use function array_values;

/**
 * Complete migration action plan with prioritized items and summary.
 */
final readonly class ActionPlan
{
    /**
     * @param list<ActionItem> $items Prioritized action items
     */
    public function __construct(
        public array $items,
        public ActionPlanSummary $summary,
    ) {
    }

    /**
     * Filter items by automation grade.
     *
     * @return list<ActionItem>
     */
    public function itemsByGrade(AutomationGrade $grade): array
    {
        return array_values(
            array_filter(
                $this->items,
                static fn (ActionItem $item): bool => $item->automationGrade === $grade,
            ),
        );
    }

    /**
     * Group items by affected file path.
     *
     * @return array<string, list<ActionItem>>
     */
    public function itemsByFile(): array
    {
        $grouped = [];

        foreach ($this->items as $item) {
            foreach ($item->findings as $entry) {
                $grouped[$entry['file']][] = $item;
            }
        }

        // Deduplicate: same item may appear multiple times per file
        foreach ($grouped as $file => $items) {
            $seen    = [];
            $unique  = [];

            foreach ($items as $item) {
                $key = $item->document->filename;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $unique[]   = $item;
            }

            $grouped[$file] = $unique;
        }

        ksort($grouped);

        return $grouped;
    }
}
```

Add imports: `use function ksort;`

**Step 2: Write tests**

Create `tests/Unit/Dto/ActionPlanTest.php`:

```php
<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\ActionItem;
use App\Dto\ActionPlan;
use App\Dto\ActionPlanSummary;
use App\Dto\AutomationGrade;
use App\Dto\ComplexityScore;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanFinding;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionPlan::class)]
final class ActionPlanTest extends TestCase
{
    #[Test]
    public function itemsByGradeFiltersCorrectly(): void
    {
        $plan = new ActionPlan(
            items: [
                $this->createActionItem(AutomationGrade::Full),
                $this->createActionItem(AutomationGrade::Manual),
                $this->createActionItem(AutomationGrade::Full),
            ],
            summary: new ActionPlanSummary(3, 3, 2, 0, 1),
        );

        self::assertCount(2, $plan->itemsByGrade(AutomationGrade::Full));
        self::assertCount(1, $plan->itemsByGrade(AutomationGrade::Manual));
        self::assertCount(0, $plan->itemsByGrade(AutomationGrade::Partial));
    }

    #[Test]
    public function itemsByFileGroupsCorrectly(): void
    {
        $finding1 = new ScanFinding(10, 'msg', 'strong', 'code', ['Doc.rst']);
        $finding2 = new ScanFinding(20, 'msg', 'strong', 'code', ['Doc.rst']);

        $item1 = $this->createActionItem(AutomationGrade::Full, [
            ['file' => 'src/Foo.php', 'finding' => $finding1],
        ]);

        $item2 = $this->createActionItem(AutomationGrade::Manual, [
            ['file' => 'src/Foo.php', 'finding' => $finding2],
            ['file' => 'src/Bar.php', 'finding' => $finding2],
        ]);

        $plan = new ActionPlan(
            items: [$item1, $item2],
            summary: new ActionPlanSummary(2, 3, 1, 0, 1),
        );

        $byFile = $plan->itemsByFile();

        self::assertCount(2, $byFile); // src/Foo.php and src/Bar.php
        self::assertCount(2, $byFile['src/Foo.php']); // both items
        self::assertCount(1, $byFile['src/Bar.php']); // only item2
    }

    /**
     * @param list<array{file: string, finding: ScanFinding}> $findings
     */
    private function createActionItem(
        AutomationGrade $grade,
        array $findings = [],
    ): ActionItem {
        if ($findings === []) {
            $findings = [
                ['file' => 'src/Default.php', 'finding' => new ScanFinding(1, 'msg', 'strong', 'code', ['Doc.rst'])],
            ];
        }

        return new ActionItem(
            document: new RstDocument(
                type: DocumentType::Deprecation,
                issueId: 99999,
                title: 'Test',
                version: '13.0',
                description: '',
                impact: null,
                migration: null,
                codeReferences: [],
                indexTags: [],
                scanStatus: ScanStatus::NotScanned,
                filename: 'Deprecation-99999-Test-' . $grade->value . '.rst',
            ),
            complexity: new ComplexityScore(1, 'test', true),
            findings: $findings,
            mappings: [],
            rectorRules: [],
            automationGrade: $grade,
        );
    }
}
```

**Step 3: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 4: Commit**

```bash
git add src/Dto/ActionPlan.php src/Dto/ActionPlanSummary.php tests/Unit/Dto/ActionPlanTest.php
git commit -m "Add ActionPlan DTO with itemsByGrade and itemsByFile helpers"
```

---

## Task 10: Create ActionPlanGenerator

**Files:**
- Create: `src/Analyzer/ActionPlanGenerator.php`
- Create: `tests/Unit/Analyzer/ActionPlanGeneratorTest.php`

**Context:** Correlates ScanResult findings with RstDocuments, generates mappings, assigns Rector rules, determines automation grade, and produces a sorted ActionPlan.

**Step 1: Write the failing test**

Create `tests/Unit/Analyzer/ActionPlanGeneratorTest.php`:

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

use App\Analyzer\ActionPlanGenerator;
use App\Analyzer\ComplexityScorer;
use App\Analyzer\MigrationMappingExtractor;
use App\Dto\AutomationGrade;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use App\Dto\ScanStatus;
use App\Generator\RectorRuleGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionPlanGenerator::class)]
final class ActionPlanGeneratorTest extends TestCase
{
    private ActionPlanGenerator $generator;

    protected function setUp(): void
    {
        $extractor = new MigrationMappingExtractor();

        $this->generator = new ActionPlanGenerator(
            new ComplexityScorer($extractor),
            $extractor,
            new RectorRuleGenerator($extractor),
        );
    }

    #[Test]
    public function generateReturnsEmptyPlanForNoFindings(): void
    {
        $scanResult = new ScanResult('/tmp/ext', []);
        $documents  = [];

        $plan = $this->generator->generate($scanResult, $documents);

        self::assertSame([], $plan->items);
        self::assertSame(0, $plan->summary->totalItems);
    }

    #[Test]
    public function generateMatchesFindingsToDocuments(): void
    {
        $doc = $this->createDocument(
            filename: 'Deprecation-12345-OldClass.rst',
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $finding = new ScanFinding(
            line: 42,
            message: 'OldClass usage',
            indicator: 'strong',
            lineContent: '$obj = new OldClass();',
            restFiles: ['Deprecation-12345-OldClass.rst'],
        );

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/MyService.php', [$finding]),
        ]);

        $plan = $this->generator->generate($scanResult, [$doc]);

        self::assertCount(1, $plan->items);
        self::assertSame('Deprecation-12345-OldClass.rst', $plan->items[0]->document->filename);
        self::assertSame(AutomationGrade::Full, $plan->items[0]->automationGrade);
        self::assertCount(1, $plan->items[0]->findings);
        self::assertNotEmpty($plan->items[0]->rectorRules);
    }

    #[Test]
    public function generateAssignsManualGradeWhenNoRectorRules(): void
    {
        $doc = $this->createDocument(
            filename: 'Breaking-99999-RemovedSomething.rst',
            migration: 'There is no direct replacement. Manual review required.',
        );

        $finding = new ScanFinding(
            line: 10,
            message: 'Removed API',
            indicator: 'strong',
            lineContent: 'code',
            restFiles: ['Breaking-99999-RemovedSomething.rst'],
        );

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/Legacy.php', [$finding]),
        ]);

        $plan = $this->generator->generate($scanResult, [$doc]);

        self::assertCount(1, $plan->items);
        self::assertSame(AutomationGrade::Manual, $plan->items[0]->automationGrade);
    }

    #[Test]
    public function generateSortsByAutomationGradeThenFileCount(): void
    {
        $docFull = $this->createDocument(
            filename: 'Deprecation-11111-Full.rst',
            migration: 'Replace :php:`\TYPO3\CMS\Core\A` with :php:`\TYPO3\CMS\Core\B`.',
            codeReferences: [new CodeReference('TYPO3\CMS\Core\A', null, CodeReferenceType::ClassName)],
        );

        $docManual = $this->createDocument(
            filename: 'Breaking-22222-Manual.rst',
            migration: 'No direct replacement available.',
        );

        $f1 = new ScanFinding(1, 'msg', 'strong', 'code', ['Deprecation-11111-Full.rst']);
        $f2 = new ScanFinding(2, 'msg', 'strong', 'code', ['Breaking-22222-Manual.rst']);
        $f3 = new ScanFinding(3, 'msg', 'strong', 'code', ['Breaking-22222-Manual.rst']);

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/A.php', [$f1]),
            new ScanFileResult('src/B.php', [$f2]),
            new ScanFileResult('src/C.php', [$f3]),
        ]);

        $plan = $this->generator->generate($scanResult, [$docFull, $docManual]);

        // Full automation items come before Manual
        self::assertSame(AutomationGrade::Full, $plan->items[0]->automationGrade);
        self::assertSame(AutomationGrade::Manual, $plan->items[1]->automationGrade);
    }

    #[Test]
    public function summaryCountsAreCorrect(): void
    {
        $docFull = $this->createDocument(
            filename: 'Deprecation-11111-Full.rst',
            migration: 'Replace :php:`\TYPO3\CMS\Core\A` with :php:`\TYPO3\CMS\Core\B`.',
            codeReferences: [new CodeReference('TYPO3\CMS\Core\A', null, CodeReferenceType::ClassName)],
        );

        $docManual = $this->createDocument(
            filename: 'Breaking-22222-Manual.rst',
            migration: 'No direct replacement.',
        );

        $f1 = new ScanFinding(1, 'msg', 'strong', 'code', ['Deprecation-11111-Full.rst']);
        $f2 = new ScanFinding(2, 'msg', 'strong', 'code', ['Breaking-22222-Manual.rst']);

        $scanResult = new ScanResult('/tmp/ext', [
            new ScanFileResult('src/A.php', [$f1, $f2]),
        ]);

        $plan = $this->generator->generate($scanResult, [$docFull, $docManual]);

        self::assertSame(2, $plan->summary->totalItems);
        self::assertSame(2, $plan->summary->totalFindings);
        self::assertSame(1, $plan->summary->fullCount);
        self::assertSame(1, $plan->summary->manualCount);
        self::assertSame(0, $plan->summary->partialCount);
    }

    /**
     * @param list<CodeReference> $codeReferences
     */
    private function createDocument(
        string $filename = 'Deprecation-99999-Test.rst',
        ?string $migration = '',
        array $codeReferences = [],
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 99999,
            title: 'Test',
            version: '13.0',
            description: '',
            impact: null,
            migration: $migration,
            codeReferences: $codeReferences,
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
        );
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Analyzer/ActionPlanGeneratorTest.php`
Expected: FAIL — class does not exist

**Step 3: Create ActionPlanGenerator**

Create `src/Analyzer/ActionPlanGenerator.php`:

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

use App\Dto\ActionItem;
use App\Dto\ActionPlan;
use App\Dto\ActionPlanSummary;
use App\Dto\AutomationGrade;
use App\Dto\RectorRule;
use App\Dto\RstDocument;
use App\Dto\ScanResult;
use App\Generator\RectorRuleGenerator;

use function array_filter;
use function array_values;
use function count;
use function usort;

/**
 * Generates a prioritized migration action plan from scan results and RST documents.
 *
 * Correlates scan findings with their referenced RST documents, resolves
 * mappings and Rector rules, assigns an automation grade, and sorts by priority.
 */
final readonly class ActionPlanGenerator
{
    public function __construct(
        private ComplexityScorer $complexityScorer,
        private MigrationMappingExtractor $mappingExtractor,
        private RectorRuleGenerator $rectorGenerator,
    ) {
    }

    /**
     * Generate a prioritized action plan from scan results and available documents.
     *
     * @param list<RstDocument> $documents All parsed RST documents for the current version range
     */
    public function generate(ScanResult $scanResult, array $documents): ActionPlan
    {
        // Build lookup: RST filename → RstDocument
        $docByFilename = [];

        foreach ($documents as $doc) {
            $docByFilename[$doc->filename] = $doc;
        }

        // Group scan findings by RST file
        $findingsByRst = $scanResult->findingsGroupedByRestFile();

        // Build action items for each referenced RST document
        $items      = [];
        $fullCount    = 0;
        $partialCount = 0;
        $manualCount  = 0;
        $totalFindings = 0;

        foreach ($findingsByRst as $rstFilename => $findingEntries) {
            if (!isset($docByFilename[$rstFilename])) {
                continue;
            }

            $doc        = $docByFilename[$rstFilename];
            $complexity = $this->complexityScorer->score($doc);
            $mappings   = $this->mappingExtractor->extract($doc->migration, $doc->description);
            $rules      = $this->rectorGenerator->generate($doc);
            $configRules = array_values(array_filter(
                $rules,
                static fn (RectorRule $r): bool => $r->isConfig(),
            ));

            $grade = $this->determineGrade($configRules, $rules);

            $items[] = new ActionItem(
                document: $doc,
                complexity: $complexity,
                findings: $findingEntries,
                mappings: $mappings,
                rectorRules: $rules,
                automationGrade: $grade,
            );

            $totalFindings += count($findingEntries);

            match ($grade) {
                AutomationGrade::Full    => ++$fullCount,
                AutomationGrade::Partial => ++$partialCount,
                AutomationGrade::Manual  => ++$manualCount,
            };
        }

        // Sort: Full first, then Partial, then Manual; within same grade by finding count desc
        usort($items, static function (ActionItem $a, ActionItem $b): int {
            $gradeOrder = [
                AutomationGrade::Full->value    => 0,
                AutomationGrade::Partial->value => 1,
                AutomationGrade::Manual->value  => 2,
            ];

            $gradeCompare = $gradeOrder[$a->automationGrade->value] <=> $gradeOrder[$b->automationGrade->value];

            if ($gradeCompare !== 0) {
                return $gradeCompare;
            }

            // More findings = higher priority (descending)
            return count($b->findings) <=> count($a->findings);
        });

        return new ActionPlan(
            items: $items,
            summary: new ActionPlanSummary(
                totalItems: count($items),
                totalFindings: $totalFindings,
                fullCount: $fullCount,
                partialCount: $partialCount,
                manualCount: $manualCount,
            ),
        );
    }

    /**
     * Determine the automation grade based on available Rector rules.
     *
     * @param list<RectorRule> $configRules Rules that generate Rector config entries
     * @param list<RectorRule> $allRules    All rules including skeletons
     */
    private function determineGrade(array $configRules, array $allRules): AutomationGrade
    {
        if ($configRules !== [] && count($configRules) === count($allRules)) {
            return AutomationGrade::Full;
        }

        if ($configRules !== []) {
            return AutomationGrade::Partial;
        }

        return AutomationGrade::Manual;
    }
}
```

**Step 4: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Analyzer/ActionPlanGenerator.php tests/Unit/Analyzer/ActionPlanGeneratorTest.php
git commit -m "Add ActionPlanGenerator to correlate scan findings with documents and Rector rules"
```

---

## Task 11: Add action plan route and controller method

**Files:**
- Modify: `src/Controller/ScanController.php`
- Create: `templates/scan/action-plan.html.twig`

**Context:** After a scan, users click "Aktionsplan" to see the prioritized plan. The controller reads the ScanResult from the session, loads all documents for the current version range, and generates the ActionPlan.

**Step 1: Add controller method**

In `src/Controller/ScanController.php`, add a new constructor dependency and route:

Add to constructor:
```php
private readonly ActionPlanGenerator $actionPlanGenerator,
```

Add imports:
```php
use App\Analyzer\ActionPlanGenerator;
use App\Dto\ActionPlan;
```

Add the new route method:

```php
/**
 * Generate and display a prioritized action plan based on scan results.
 */
#[Route('/scan/action-plan', name: 'scan_action_plan')]
public function actionPlan(Request $request): Response
{
    $result = $this->getSessionResult($request);

    if (!$result instanceof ScanResult) {
        return $this->redirectToRoute('scan_index');
    }

    $documents = $this->documentService->getDocuments();
    $plan      = $this->actionPlanGenerator->generate($result, $documents);

    return $this->render('scan/action-plan.html.twig', [
        'plan'          => $plan,
        'result'        => $result,
        'versionRange'  => $this->documentService->getVersionRange(),
        'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
    ]);
}
```

**Step 2: Create a minimal template**

Create `templates/scan/action-plan.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Aktionsplan{% endblock %}

{% block breadcrumb %}
    <li class="breadcrumb-item"><a href="{{ path('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ path('scan_index') }}">Extension scannen</a></li>
    <li class="breadcrumb-item active">Aktionsplan</li>
{% endblock %}

{% block body %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Aktionsplan</h1>
        <p class="text-muted mb-0">{{ plan.summary.totalItems }} Aktionen, {{ plan.summary.totalFindings }} Fundstellen</p>
    </div>
    <div class="d-flex gap-2">
        {% if plan.summary.fullCount > 0 %}
        <a href="{{ path('scan_export_rector_config') }}" class="btn btn-success btn-sm">
            <i class="bi bi-download me-1"></i>Rector-Config exportieren
        </a>
        {% endif %}
        <a href="{{ path('scan_index') }}" class="btn btn-typo3 btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Neuer Scan
        </a>
    </div>
</div>

{# Summary cards #}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1 text-success">{{ plan.summary.fullCount }}</div>
                <small class="text-muted">Vollautomatisch</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1 text-warning">{{ plan.summary.partialCount }}</div>
                <small class="text-muted">Teilautomatisch</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1 text-danger">{{ plan.summary.manualCount }}</div>
                <small class="text-muted">Manuell</small>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div class="h2 mb-1">{{ plan.summary.totalItems }}</div>
                <small class="text-muted">Aktionen gesamt</small>
            </div>
        </div>
    </div>
</div>

{# Tab navigation #}
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="by-grade-tab" data-bs-toggle="tab"
                data-bs-target="#by-grade-pane" type="button" role="tab">
            <i class="bi bi-lightning me-1"></i>Nach Automatisierung
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="by-file-tab" data-bs-toggle="tab"
                data-bs-target="#by-file-pane" type="button" role="tab">
            <i class="bi bi-file-earmark-code me-1"></i>Nach Datei
        </button>
    </li>
</ul>

<div class="tab-content">
    {# View 1: By Automation Grade #}
    <div class="tab-pane fade show active" id="by-grade-pane" role="tabpanel">
        {% for grade, label, colorClass in [
            ['full', 'Vollautomatisch — Rector-Config anwenden', 'success'],
            ['partial', 'Teilautomatisch — Rector + manuelle Anpassung', 'warning'],
            ['manual', 'Manuell — kein Rector-Support', 'danger']
        ] %}
            {% set gradeItems = plan.itemsByGrade(constant('App\\Dto\\AutomationGrade::' ~ grade|capitalize)) %}
            {% if gradeItems|length > 0 %}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-medium">
                        <span class="badge text-bg-{{ colorClass }} me-1">{{ gradeItems|length }}</span>
                        {{ label }}
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Dokument</th>
                                <th>Version</th>
                                <th class="text-center">Dateien</th>
                                <th class="text-center">Findings</th>
                                <th class="text-center">Complexity</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {% for item in gradeItems %}
                            <tr>
                                <td>
                                    <a href="{{ path('deprecation_detail', {filename: item.document.filename}) }}" class="text-decoration-none">
                                        {{ item.document.title }}
                                    </a>
                                </td>
                                <td><span class="badge text-bg-secondary">{{ item.document.version }}</span></td>
                                <td class="text-center">{{ item.affectedFileCount() }}</td>
                                <td class="text-center">{{ item.findings|length }}</td>
                                <td class="text-center">
                                    <span class="badge text-bg-{{ item.complexity.score <= 2 ? 'success' : (item.complexity.score <= 3 ? 'warning' : 'danger') }}">
                                        {{ item.complexity.score }}/5
                                    </span>
                                </td>
                                <td class="text-end">
                                    {% if item.rectorRules|length > 0 %}
                                    <a href="{{ path('matcher_generate', {filename: item.document.filename}) }}" class="btn btn-outline-primary btn-sm" title="Details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    {% endif %}
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

    {# View 2: By File #}
    <div class="tab-pane fade" id="by-file-pane" role="tabpanel">
        {% for filePath, items in plan.itemsByFile() %}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-medium"><i class="bi bi-file-earmark-code me-1"></i>{{ filePath }}</span>
                <span class="badge text-bg-primary">{{ items|length }} Aktion{{ items|length != 1 ? 'en' : '' }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Dokument</th>
                            <th class="text-center">Automatisierung</th>
                            <th class="text-center">Complexity</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for item in items %}
                        <tr>
                            <td>
                                <a href="{{ path('deprecation_detail', {filename: item.document.filename}) }}" class="text-decoration-none">
                                    {{ item.document.title }}
                                </a>
                            </td>
                            <td class="text-center">
                                {% if item.automationGrade.value == 'full' %}
                                    <span class="badge text-bg-success">Automatisch</span>
                                {% elseif item.automationGrade.value == 'partial' %}
                                    <span class="badge text-bg-warning">Teilweise</span>
                                {% else %}
                                    <span class="badge text-bg-danger">Manuell</span>
                                {% endif %}
                            </td>
                            <td class="text-center">
                                <span class="badge text-bg-{{ item.complexity.score <= 2 ? 'success' : (item.complexity.score <= 3 ? 'warning' : 'danger') }}">
                                    {{ item.complexity.score }}/5
                                </span>
                            </td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
        {% endfor %}
    </div>
</div>
{% endblock %}
```

**Step 3: Add "Aktionsplan" button to scan result page**

In `templates/scan/result.html.twig`, add a button next to "Neuer Scan" in the header (around line 34):

```twig
<a href="{{ path('scan_action_plan') }}" class="btn btn-success">
    <i class="bi bi-list-check me-1"></i>Aktionsplan
</a>
```

**Step 4: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 5: Commit**

```bash
git add src/Controller/ScanController.php templates/scan/action-plan.html.twig templates/scan/result.html.twig
git commit -m "Add action plan controller route and two-view template"
```

---

## Task 12: Add combined Rector-Config-Export for action plan

**Files:**
- Modify: `src/Controller/ScanController.php`
- Modify: `src/Generator/RectorRuleGenerator.php`

**Context:** Users should be able to download a single `rector.php` containing all fully-automatable rules from the action plan. This combines config rules from all action items with AutomationGrade::Full.

**Step 1: Add export route to ScanController**

Add the new route method:

```php
/**
 * Export a combined Rector config with all fully-automatable rules from the action plan.
 */
#[Route('/scan/export-rector-config', name: 'scan_export_rector_config')]
public function exportRectorConfig(Request $request): Response
{
    $result = $this->getSessionResult($request);

    if (!$result instanceof ScanResult) {
        return $this->redirectToRoute('scan_index');
    }

    $documents = $this->documentService->getDocuments();
    $plan      = $this->actionPlanGenerator->generate($result, $documents);

    // Collect all config rules from fully and partially automatable items
    $allConfigRules = [];

    foreach ($plan->items as $item) {
        if ($item->automationGrade === AutomationGrade::Manual) {
            continue;
        }

        foreach ($item->rectorRules as $rule) {
            if ($rule->isConfig()) {
                $allConfigRules[] = $rule;
            }
        }
    }

    if ($allConfigRules === []) {
        $this->addFlash('warning', 'Keine automatisierbaren Rector-Rules gefunden.');

        return $this->redirectToRoute('scan_action_plan');
    }

    $phpCode = $this->rectorGenerator->renderConfig($allConfigRules);

    $disposition = HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        'rector.php',
    );

    $response = new Response($phpCode);
    $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
    $response->headers->set('Content-Disposition', $disposition);

    return $response;
}
```

Add the missing imports:

```php
use App\Dto\AutomationGrade;
use App\Generator\RectorRuleGenerator;
```

Add `RectorRuleGenerator $rectorGenerator` to the constructor.

**Step 2: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 3: Commit**

```bash
git add src/Controller/ScanController.php
git commit -m "Add combined Rector-Config-Export for action plan"
```

---

## Task 13: Run pattern analysis and verify improvement

**Files:**
- Modify: `bin/analyze-patterns.php` (temporary analysis script)

**Context:** After all changes, run the analysis script again to measure the improvement in pattern recognition.

**Step 1: Update and run the analysis script**

The existing `bin/analyze-patterns.php` script should be updated to pass the description text too. Then run:

```bash
docker compose exec phpfpm php bin/analyze-patterns.php 2>&1
```

**Step 2: Compare before/after**

Before: 8/670 (1.2%) documents with mappings
Expected: Significant improvement (ideally 50+)

**Step 3: Clean up analysis scripts**

Remove the temporary analysis scripts:

```bash
rm bin/analyze-patterns.php bin/analyze-patterns-deep.php
```

**Step 4: Run final CI**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 5: Commit**

```bash
git add -A
git commit -m "Remove temporary analysis scripts"
```

---

## Task 14: Update CLAUDE.md roadmap

**Files:**
- Modify: `CLAUDE.md`

**Context:** Mark completed features and add Rector-Ausführung to future roadmap.

**Step 1: Update roadmap**

In `CLAUDE.md`, update the roadmap section to reflect completed items:
- Mark "Rector-Rule-Skeleton-Generator" as done (already existed)
- Mark "Extension scannen" features as done
- Mark "Migration-Mapping: Alt->Neu Zuordnung" as done
- Mark "Komplexitäts-Scoring" as done
- Add "Rector-Ausführung" under a future version
- Add "End-to-End Aktionsplan" as completed in v1.1

**Step 2: Run tests**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "Update roadmap with completed End-to-End Pipeline features"
```

---

## Important Notes for Implementation

### Pre-commit checklist (EVERY commit)

1. `docker compose exec phpfpm composer ci:cgl` — auto-fix code style
2. `docker compose exec phpfpm composer ci:rector` — auto-fix rector rules
3. Review and stage any auto-fix changes
4. `docker compose exec phpfpm composer ci:test` — must be green
5. Commit (no Co-Authored-By, English message, no prefix convention)
6. Code review, fix findings immediately

### Key files to reference

| File | Purpose |
|------|---------|
| `src/Dto/CodeReference.php` | Parse `:php:` role values (Task 2-3) |
| `src/Dto/CodeReferenceType.php` | Enum for reference types (Task 1) |
| `src/Analyzer/MigrationMappingExtractor.php` | Text pattern matching (Task 5-6) |
| `src/Analyzer/ComplexityScorer.php` | Scoring rules (Task 6) |
| `src/Generator/RectorRuleGenerator.php` | Rector rule generation (Task 4, 6) |
| `src/Generator/MatcherConfigGenerator.php` | Matcher generation (Task 4) |
| `src/Controller/ScanController.php` | Scan routes (Task 11-12) |
| `templates/scan/result.html.twig` | Scan results UI (Task 11) |

### Backward compatibility

- `CodeReference::$resolutionConfidence` defaults to `1.0` — all existing code is unaffected
- `MigrationMappingExtractor::extract()` second parameter defaults to `null` — all existing callers are unaffected
- New `CodeReferenceType` enum cases don't break `match` expressions that have `default` branches
- Generators filter on `resolutionConfidence >= 0.9` to preserve output quality
