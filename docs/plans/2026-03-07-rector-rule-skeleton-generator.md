# Rector Rule Skeleton Generator Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Generate Rector rule configurations and skeleton classes from TYPO3 RST deprecation documents, enabling automated code migration for simple rename patterns and providing scaffolded Rule classes for complex cases.

**Architecture:** A `MigrationMappingExtractor` parses the RST migration section for old→new API pairs using regex patterns on `:php:` role references. A `RectorRuleGenerator` uses these mappings to produce two output types: `rector.php` configuration snippets for simple renames (class, method, constant) and Rule class skeletons for complex cases. Both integrate as tabs on the existing matcher generate page. The data flow mirrors the existing matcher pipeline: `RstDocument → extract mappings → generate rules → render PHP`.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5 Tabs, PHPUnit 13

---

### Task 1: Create DTOs

**Files:**
- Create: `src/Dto/RectorRuleType.php`
- Create: `src/Dto/MigrationMapping.php`
- Create: `src/Dto/RectorRule.php`

**Step 1: Create `RectorRuleType` enum**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

enum RectorRuleType: string
{
    case RenameClass = 'rename_class';
    case RenameMethod = 'rename_method';
    case RenameStaticMethod = 'rename_static_method';
    case RenameClassConstant = 'rename_class_constant';
    case Skeleton = 'skeleton';
}
```

**Step 2: Create `MigrationMapping` DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents a detected old→new API mapping from an RST migration section.
 */
final readonly class MigrationMapping
{
    public function __construct(
        public CodeReference $source,
        public CodeReference $target,
        public float $confidence,
    ) {
    }
}
```

**Step 3: Create `RectorRule` DTO**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents a generated Rector rule — either a rector.php config entry or a Rule class skeleton.
 */
final readonly class RectorRule
{
    public function __construct(
        public RectorRuleType $type,
        public CodeReference $source,
        public ?CodeReference $target,
        public string $description,
        public string $rstFilename,
    ) {
    }

    /**
     * Whether this rule can be expressed as a rector.php configuration entry.
     */
    public function isConfig(): bool
    {
        return $this->type !== RectorRuleType::Skeleton;
    }
}
```

**Step 4: Run PHPStan**

Run: `docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M`
Expected: 0 errors

**Step 5: Commit**

```
git add src/Dto/RectorRuleType.php src/Dto/MigrationMapping.php src/Dto/RectorRule.php
git commit -m "Add Rector DTOs: RectorRuleType, MigrationMapping, RectorRule"
git push
```

---

### Task 2: Create RST test fixtures for migration patterns

**Files:**
- Create: `tests/Fixtures/Rst/Deprecation-11111-RenamedClass.rst`
- Create: `tests/Fixtures/Rst/Deprecation-11112-RenamedStaticMethod.rst`
- Create: `tests/Fixtures/Rst/Deprecation-11113-UseInstead.rst`
- Create: `tests/Fixtures/Rst/Breaking-11114-RemovedWithoutReplacement.rst`

**Step 1: Create fixture for class rename pattern**

`tests/Fixtures/Rst/Deprecation-11111-RenamedClass.rst`:

```rst
.. include:: /Includes.rst.txt

.. _deprecation-11111:

=============================================
Deprecation: #11111 - OldUtility class renamed
=============================================

See :issue:`11111`

.. index:: PHP-API, FullyScanned, ext:core

Description
===========

The class :php:`\TYPO3\CMS\Core\Utility\OldUtility` has been deprecated.

Impact
======

Using :php:`\TYPO3\CMS\Core\Utility\OldUtility` will trigger a deprecation warning.

Migration
=========

Replace :php:`\TYPO3\CMS\Core\Utility\OldUtility` with :php:`\TYPO3\CMS\Core\Utility\NewUtility`.
```

**Step 2: Create fixture for static method rename pattern**

`tests/Fixtures/Rst/Deprecation-11112-RenamedStaticMethod.rst`:

```rst
.. include:: /Includes.rst.txt

.. _deprecation-11112:

=====================================================
Deprecation: #11112 - Static method calculate renamed
=====================================================

See :issue:`11112`

.. index:: PHP-API, FullyScanned, ext:core

Description
===========

The method :php:`\TYPO3\CMS\Core\Service\MathService::calculate()` has been deprecated.

Impact
======

Calling :php:`\TYPO3\CMS\Core\Service\MathService::calculate()` will trigger a deprecation warning.

Migration
=========

The method :php:`\TYPO3\CMS\Core\Service\MathService::calculate()` has been renamed
to :php:`\TYPO3\CMS\Core\Service\MathService::compute()`.
```

**Step 3: Create fixture for "Use X instead of Y" pattern**

`tests/Fixtures/Rst/Deprecation-11113-UseInstead.rst`:

```rst
.. include:: /Includes.rst.txt

.. _deprecation-11113:

==============================================
Deprecation: #11113 - DeprecatedHelper removed
==============================================

See :issue:`11113`

.. index:: PHP-API, PartiallyScanned, ext:backend

Description
===========

The class :php:`\TYPO3\CMS\Backend\Helper\DeprecatedHelper` has been deprecated.

Impact
======

Using :php:`\TYPO3\CMS\Backend\Helper\DeprecatedHelper` will trigger a deprecation warning.

Migration
=========

Use :php:`\TYPO3\CMS\Backend\Helper\ModernHelper` instead of :php:`\TYPO3\CMS\Backend\Helper\DeprecatedHelper`.
```

**Step 4: Create fixture for removal without replacement (skeleton case)**

`tests/Fixtures/Rst/Breaking-11114-RemovedWithoutReplacement.rst`:

```rst
.. include:: /Includes.rst.txt

.. _breaking-11114:

==============================================
Breaking: #11114 - LegacyRenderer removed
==============================================

See :issue:`11114`

.. index:: PHP-API, NotScanned, ext:fluid

Description
===========

The class :php:`\TYPO3\CMS\Fluid\View\LegacyRenderer` and its method
:php:`\TYPO3\CMS\Fluid\View\LegacyRenderer->renderSection()` have been removed.

Impact
======

Calling these APIs will result in a fatal error.

Migration
=========

There is no direct replacement. Implement custom rendering logic using
the new Fluid API.
```

**Step 5: Commit**

```
git add tests/Fixtures/Rst/Deprecation-11111-RenamedClass.rst \
        tests/Fixtures/Rst/Deprecation-11112-RenamedStaticMethod.rst \
        tests/Fixtures/Rst/Deprecation-11113-UseInstead.rst \
        tests/Fixtures/Rst/Breaking-11114-RemovedWithoutReplacement.rst
git commit -m "Add RST test fixtures for Rector migration patterns"
git push
```

---

### Task 3: Implement MigrationMappingExtractor (TDD)

**Files:**
- Create: `tests/Unit/Analyzer/MigrationMappingExtractorTest.php`
- Create: `src/Analyzer/MigrationMappingExtractor.php`

**Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeReferenceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationMappingExtractorTest extends TestCase
{
    private MigrationMappingExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MigrationMappingExtractor();
    }

    #[Test]
    public function extractReplaceWithPattern(): void
    {
        $text = 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
        self::assertSame(CodeReferenceType::ClassName, $mappings[0]->source->type);
        self::assertSame(1.0, $mappings[0]->confidence);
    }

    #[Test]
    public function extractRenamedToPattern(): void
    {
        $text = 'The method :php:`\TYPO3\CMS\Core\Service::oldMethod()` has been renamed '
            . 'to :php:`\TYPO3\CMS\Core\Service::newMethod()`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\Service', $mappings[0]->source->className);
        self::assertSame('oldMethod', $mappings[0]->source->member);
        self::assertSame(CodeReferenceType::StaticMethod, $mappings[0]->source->type);
        self::assertSame('newMethod', $mappings[0]->target->member);
        self::assertSame(1.0, $mappings[0]->confidence);
    }

    #[Test]
    public function extractUseInsteadOfPattern(): void
    {
        $text = 'Use :php:`\TYPO3\CMS\Core\NewHelper` instead of :php:`\TYPO3\CMS\Core\OldHelper`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        // "Use NEW instead of OLD" — OLD is source, NEW is target
        self::assertSame('TYPO3\CMS\Core\OldHelper', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewHelper', $mappings[0]->target->className);
        self::assertSame(0.9, $mappings[0]->confidence);
    }

    #[Test]
    public function extractMigrateToPattern(): void
    {
        $text = 'Migrate from :php:`\TYPO3\CMS\Core\OldApi` to :php:`\TYPO3\CMS\Core\NewApi`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\OldApi', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewApi', $mappings[0]->target->className);
        self::assertSame(1.0, $mappings[0]->confidence);
    }

    #[Test]
    public function extractReturnsEmptyForNullText(): void
    {
        self::assertSame([], $this->extractor->extract(null));
    }

    #[Test]
    public function extractReturnsEmptyForEmptyText(): void
    {
        self::assertSame([], $this->extractor->extract(''));
    }

    #[Test]
    public function extractReturnsEmptyWhenNoPatternMatches(): void
    {
        $text = 'There is no direct replacement. Implement custom logic.';

        self::assertSame([], $this->extractor->extract($text));
    }

    #[Test]
    public function extractSkipsNonFqcnReferences(): void
    {
        // References without namespace separator are not valid FQCNs
        $text = 'Replace :php:`oldFunction()` with :php:`newFunction()`.';

        self::assertSame([], $this->extractor->extract($text));
    }

    #[Test]
    public function extractMultipleMappingsFromSameText(): void
    {
        $text = "Replace :php:`\\TYPO3\\CMS\\Core\\OldClass` with :php:`\\TYPO3\\CMS\\Core\\NewClass`.\n\n"
            . 'The method :php:`\TYPO3\CMS\Core\Service::oldMethod()` has been renamed '
            . 'to :php:`\TYPO3\CMS\Core\Service::newMethod()`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(2, $mappings);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter MigrationMappingExtractorTest`
Expected: FAIL — class `MigrationMappingExtractor` not found

**Step 3: Implement `MigrationMappingExtractor`**

```php
<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CodeReference;
use App\Dto\MigrationMapping;

use function preg_match_all;

use const PREG_SET_ORDER;

final class MigrationMappingExtractor
{
    /**
     * Mapping patterns with [regex, sourceGroup, targetGroup, confidence].
     *
     * Each pattern captures two :php: role references. The group indices
     * indicate which capture is the source (old) and which is the target (new).
     *
     * @var list<array{string, int, int, float}>
     */
    private const MAPPING_PATTERNS = [
        // "Replace :php:`Old` with/by :php:`New`"
        ['/\b[Rr]eplace\b.*?:php:`([^`]+)`.*?\b(?:with|by)\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
        // ":php:`Old` has been/was renamed to :php:`New`"
        ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+renamed\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
        // "Use :php:`New` instead of :php:`Old`" (note: reversed order)
        ['/\b[Uu]se\b.*?:php:`([^`]+)`.*?\binstead\s+of\b.*?:php:`([^`]+)`/s', 2, 1, 0.9],
        // "Migrate [from] :php:`Old` to :php:`New`"
        ['/\b[Mm]igrate\b.*?(?:from\s+)?:php:`([^`]+)`.*?\bto\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
    ];

    /**
     * Extract old→new API mappings from RST migration text.
     *
     * @return list<MigrationMapping>
     */
    public function extract(?string $migrationText): array
    {
        if ($migrationText === null || $migrationText === '') {
            return [];
        }

        $mappings = [];

        foreach (self::MAPPING_PATTERNS as [$pattern, $sourceGroup, $targetGroup, $confidence]) {
            if (preg_match_all($pattern, $migrationText, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $source = CodeReference::fromPhpRole($match[$sourceGroup]);
                $target = CodeReference::fromPhpRole($match[$targetGroup]);

                if ($source === null || $target === null) {
                    continue;
                }

                $mappings[] = new MigrationMapping($source, $target, $confidence);
            }
        }

        return $mappings;
    }
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter MigrationMappingExtractorTest`
Expected: OK (9 tests)

**Step 5: Run PHPStan**

Run: `docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M`
Expected: 0 errors

**Step 6: Commit**

```
git add src/Analyzer/MigrationMappingExtractor.php \
        tests/Unit/Analyzer/MigrationMappingExtractorTest.php
git commit -m "Add MigrationMappingExtractor for old-to-new API pair detection"
git push
```

---

### Task 4: Implement RectorRuleGenerator — generate() (TDD)

**Files:**
- Create: `tests/Unit/Generator/RectorRuleGeneratorTest.php`
- Create: `src/Generator/RectorRuleGenerator.php`

**Step 1: Write the failing tests for `generate()`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\RectorRuleGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RectorRuleGeneratorTest extends TestCase
{
    private RectorRuleGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RectorRuleGenerator(
            new MigrationMappingExtractor(),
        );
    }

    #[Test]
    public function generateClassRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $rules = $this->generator->generate($doc);

        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameClass, $configRules[0]->type);
        self::assertSame('TYPO3\CMS\Core\OldClass', $configRules[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewClass', $configRules[0]->target->className);
        self::assertTrue($configRules[0]->isConfig());
    }

    #[Test]
    public function generateStaticMethodRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: ':php:`\TYPO3\CMS\Core\Service::oldMethod()` has been renamed '
                . 'to :php:`\TYPO3\CMS\Core\Service::newMethod()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Service', 'oldMethod', CodeReferenceType::StaticMethod),
            ],
        );

        $rules = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameStaticMethod, $configRules[0]->type);
        self::assertSame('oldMethod', $configRules[0]->source->member);
        self::assertSame('newMethod', $configRules[0]->target->member);
    }

    #[Test]
    public function generateInstanceMethodRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\Foo->oldMethod()` with '
                . ':php:`\TYPO3\CMS\Core\Foo->newMethod()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'oldMethod', CodeReferenceType::InstanceMethod),
            ],
        );

        $rules = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameMethod, $configRules[0]->type);
    }

    #[Test]
    public function generateConstantRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: ':php:`\TYPO3\CMS\Core\Conf::OLD_CONST` has been renamed '
                . 'to :php:`\TYPO3\CMS\Core\Conf::NEW_CONST`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Conf', 'OLD_CONST', CodeReferenceType::ClassConstant),
            ],
        );

        $rules = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameClassConstant, $configRules[0]->type);
    }

    #[Test]
    public function generateSkeletonForCodeRefWithoutMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'There is no direct replacement.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Legacy', null, CodeReferenceType::ClassName),
            ],
        );

        $rules = $this->generator->generate($doc);
        $skeletonRules = $this->filterSkeletons($rules);

        self::assertCount(1, $skeletonRules);
        self::assertSame(RectorRuleType::Skeleton, $skeletonRules[0]->type);
        self::assertNull($skeletonRules[0]->target);
        self::assertFalse($skeletonRules[0]->isConfig());
    }

    #[Test]
    public function generateSkeletonForCodeRefNotCoveredByMapping(): void
    {
        // Migration maps OldClass → NewClass, but SecondClass has no mapping
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
                new CodeReference('TYPO3\CMS\Core\SecondClass', null, CodeReferenceType::ClassName),
            ],
        );

        $rules = $this->generator->generate($doc);

        self::assertCount(2, $rules);
        self::assertCount(1, $this->filterConfig($rules));
        self::assertCount(1, $this->filterSkeletons($rules));
    }

    #[Test]
    public function generateReturnsEmptyForNoCodeRefsAndNoMappings(): void
    {
        $doc = $this->createDocument(migration: null, codeReferences: []);

        self::assertSame([], $this->generator->generate($doc));
    }

    #[Test]
    public function generateSkeletonForMismatchedTypes(): void
    {
        // Migration maps a class name to a static method — type mismatch → skeleton
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewService::create()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $rules = $this->generator->generate($doc);

        // Mismatched types → mapping produces skeleton, and code ref also produces skeleton
        // but should not produce config
        self::assertSame(0, \count($this->filterConfig($rules)));
    }

    /**
     * @param list<CodeReference> $codeReferences
     */
    private function createDocument(
        ?string $migration,
        array $codeReferences = [],
        string $filename = 'Deprecation-99999-Test.rst',
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 99999,
            title: 'Test document',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: $migration,
            codeReferences: $codeReferences,
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
        );
    }

    /**
     * @param list<RectorRule> $rules
     *
     * @return list<RectorRule>
     */
    private function filterConfig(array $rules): array
    {
        return array_values(array_filter($rules, static fn (RectorRule $r): bool => $r->isConfig()));
    }

    /**
     * @param list<RectorRule> $rules
     *
     * @return list<RectorRule>
     */
    private function filterSkeletons(array $rules): array
    {
        return array_values(array_filter($rules, static fn (RectorRule $r): bool => !$r->isConfig()));
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter RectorRuleGeneratorTest`
Expected: FAIL — class `RectorRuleGenerator` not found

**Step 3: Implement `RectorRuleGenerator::generate()`**

```php
<?php

declare(strict_types=1);

namespace App\Generator;

use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\MigrationMapping;
use App\Dto\RectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;

use function array_filter;
use function array_values;
use function count;
use function implode;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function str_replace;

use const PATHINFO_FILENAME;

final class RectorRuleGenerator
{
    public function __construct(
        private readonly MigrationMappingExtractor $extractor,
    ) {
    }

    /**
     * Generate Rector rules from a document's migration section and code references.
     *
     * @return list<RectorRule>
     */
    public function generate(RstDocument $document): array
    {
        $mappings = $this->extractor->extract($document->migration);
        $rules = [];
        $handledSourceKeys = [];

        // 1. Generate config rules from detected mappings
        foreach ($mappings as $mapping) {
            $ruleType = $this->resolveConfigType($mapping);

            if ($ruleType === null) {
                continue;
            }

            $rules[] = new RectorRule(
                type: $ruleType,
                source: $mapping->source,
                target: $mapping->target,
                description: $document->title,
                rstFilename: $document->filename,
            );

            $handledSourceKeys[$this->buildRefKey($mapping->source)] = true;
        }

        // 2. Generate skeleton rules for code references without mappings
        foreach ($document->codeReferences as $ref) {
            $key = $this->buildRefKey($ref);

            if (isset($handledSourceKeys[$key])) {
                continue;
            }

            $rules[] = new RectorRule(
                type: RectorRuleType::Skeleton,
                source: $ref,
                target: null,
                description: $document->title,
                rstFilename: $document->filename,
            );
        }

        return $rules;
    }

    /**
     * Determine the config Rector rule type from a mapping, or null if types are incompatible.
     */
    private function resolveConfigType(MigrationMapping $mapping): ?RectorRuleType
    {
        return match (true) {
            $mapping->source->type === CodeReferenceType::ClassName
                && $mapping->target->type === CodeReferenceType::ClassName
                => RectorRuleType::RenameClass,

            $mapping->source->type === CodeReferenceType::InstanceMethod
                && $mapping->target->type === CodeReferenceType::InstanceMethod
                => RectorRuleType::RenameMethod,

            $mapping->source->type === CodeReferenceType::StaticMethod
                && $mapping->target->type === CodeReferenceType::StaticMethod
                => RectorRuleType::RenameStaticMethod,

            $mapping->source->type === CodeReferenceType::ClassConstant
                && $mapping->target->type === CodeReferenceType::ClassConstant
                => RectorRuleType::RenameClassConstant,

            default => null,
        };
    }

    private function buildRefKey(CodeReference $ref): string
    {
        return $ref->className . '::' . ($ref->member ?? '');
    }
}
```

> **Note:** The `renderConfig()` and `renderSkeleton()` methods are added in Tasks 5 and 6. Leave the `use` imports for `implode`, `pathinfo`, `preg_replace`, `sprintf`, `str_replace`, `PATHINFO_FILENAME` in place — they will be needed by those methods. If PHPStan complains about unused imports, temporarily remove them and re-add in the later tasks.

**Step 4: Run tests to verify they pass**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter RectorRuleGeneratorTest`
Expected: OK (8 tests)

**Step 5: Run PHPStan**

Run: `docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M`
Expected: 0 errors (remove unused imports if needed)

**Step 6: Commit**

```
git add src/Generator/RectorRuleGenerator.php \
        tests/Unit/Generator/RectorRuleGeneratorTest.php
git commit -m "Add RectorRuleGenerator with config/skeleton rule generation"
git push
```

---

### Task 5: Implement RectorRuleGenerator — renderConfig() (TDD)

**Files:**
- Modify: `tests/Unit/Generator/RectorRuleGeneratorTest.php`
- Modify: `src/Generator/RectorRuleGenerator.php`

**Step 1: Add failing tests for `renderConfig()`**

Append to `RectorRuleGeneratorTest.php`:

```php
#[Test]
public function renderConfigForClassRename(): void
{
    $rules = [
        new RectorRule(
            RectorRuleType::RenameClass,
            new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            new CodeReference('TYPO3\CMS\Core\NewClass', null, CodeReferenceType::ClassName),
            'Test',
            'Test.rst',
        ),
    ];

    $output = $this->generator->renderConfig($rules);

    self::assertStringContainsString('declare(strict_types=1);', $output);
    self::assertStringContainsString('use Rector\Config\RectorConfig;', $output);
    self::assertStringContainsString('RenameClassRector::class', $output);
    self::assertStringContainsString("'TYPO3\\CMS\\Core\\OldClass' => 'TYPO3\\CMS\\Core\\NewClass'", $output);
}

#[Test]
public function renderConfigForMethodRename(): void
{
    $rules = [
        new RectorRule(
            RectorRuleType::RenameMethod,
            new CodeReference('TYPO3\CMS\Core\Foo', 'oldMethod', CodeReferenceType::InstanceMethod),
            new CodeReference('TYPO3\CMS\Core\Foo', 'newMethod', CodeReferenceType::InstanceMethod),
            'Test',
            'Test.rst',
        ),
    ];

    $output = $this->generator->renderConfig($rules);

    self::assertStringContainsString('RenameMethodRector::class', $output);
    self::assertStringContainsString('MethodCallRename', $output);
    self::assertStringContainsString("'TYPO3\\CMS\\Core\\Foo'", $output);
    self::assertStringContainsString("'oldMethod'", $output);
    self::assertStringContainsString("'newMethod'", $output);
}

#[Test]
public function renderConfigForStaticMethodRename(): void
{
    $rules = [
        new RectorRule(
            RectorRuleType::RenameStaticMethod,
            new CodeReference('TYPO3\CMS\Core\Old', 'calc', CodeReferenceType::StaticMethod),
            new CodeReference('TYPO3\CMS\Core\New', 'compute', CodeReferenceType::StaticMethod),
            'Test',
            'Test.rst',
        ),
    ];

    $output = $this->generator->renderConfig($rules);

    self::assertStringContainsString('RenameStaticMethodRector::class', $output);
    self::assertStringContainsString('RenameStaticMethod(', $output);
}

#[Test]
public function renderConfigForConstantRename(): void
{
    $rules = [
        new RectorRule(
            RectorRuleType::RenameClassConstant,
            new CodeReference('TYPO3\CMS\Core\Conf', 'OLD_CONST', CodeReferenceType::ClassConstant),
            new CodeReference('TYPO3\CMS\Core\Conf', 'NEW_CONST', CodeReferenceType::ClassConstant),
            'Test',
            'Test.rst',
        ),
    ];

    $output = $this->generator->renderConfig($rules);

    self::assertStringContainsString('RenameClassConstFetchRector::class', $output);
    self::assertStringContainsString('RenameClassAndConstFetch(', $output);
}

#[Test]
public function renderConfigReturnsEmptyStringForNoConfigRules(): void
{
    self::assertSame('', $this->generator->renderConfig([]));
}

#[Test]
public function renderConfigSkipsSkeletonRules(): void
{
    $rules = [
        new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Legacy', null, CodeReferenceType::ClassName),
            null,
            'Test',
            'Test.rst',
        ),
    ];

    self::assertSame('', $this->generator->renderConfig($rules));
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter RectorRuleGeneratorTest`
Expected: FAIL — method `renderConfig` not found

**Step 3: Implement `renderConfig()`**

Add to `RectorRuleGenerator`:

```php
/**
 * Render config-type rules as a rector.php configuration file.
 *
 * @param list<RectorRule> $rules
 */
public function renderConfig(array $rules): string
{
    $configRules = array_values(array_filter(
        $rules,
        static fn (RectorRule $r): bool => $r->isConfig(),
    ));

    if ([] === $configRules) {
        return '';
    }

    // Group rules by Rector class and collect imports + entries
    $imports = ['Rector\Config\RectorConfig'];
    $groups  = [];

    foreach ($configRules as $rule) {
        [$rectorShortName, $ruleImports, $entry] = $this->resolveRectorConfig($rule);

        foreach ($ruleImports as $import) {
            $imports[] = $import;
        }

        $groups[$rectorShortName][] = $entry;
    }

    $imports = array_values(array_unique($imports));
    sort($imports);

    // Render PHP file
    $output = "<?php\n\ndeclare(strict_types=1);\n\n";

    foreach ($imports as $import) {
        $escaped = str_replace('\\', '\\\\', $import);
        $output .= sprintf("use %s;\n", $import);
    }

    $output .= "\nreturn RectorConfig::configure()\n";
    $output .= "    ->withConfiguredRules([\n";

    foreach ($groups as $shortName => $entries) {
        $output .= sprintf("        %s::class => [\n", $shortName);

        foreach ($entries as $entry) {
            $output .= sprintf("            %s,\n", $entry);
        }

        $output .= "        ],\n";
    }

    $output .= "    ]);\n";

    return $output;
}

/**
 * Resolve Rector class, imports, and config entry for a single rule.
 *
 * @return array{string, list<string>, string}
 */
private function resolveRectorConfig(RectorRule $rule): array
{
    return match ($rule->type) {
        RectorRuleType::RenameClass => [
            'RenameClassRector',
            ['Rector\Renaming\Rector\Name\RenameClassRector'],
            sprintf(
                "'%s' => '%s'",
                $this->escapePhpString($rule->source->className),
                $this->escapePhpString($rule->target->className),
            ),
        ],
        RectorRuleType::RenameMethod => [
            'RenameMethodRector',
            [
                'Rector\Renaming\Rector\MethodCall\RenameMethodRector',
                'Rector\Renaming\ValueObject\MethodCallRename',
            ],
            sprintf(
                "new MethodCallRename('%s', '%s', '%s')",
                $this->escapePhpString($rule->source->className),
                $this->escapePhpString($rule->source->member ?? ''),
                $this->escapePhpString($rule->target->member ?? ''),
            ),
        ],
        RectorRuleType::RenameStaticMethod => [
            'RenameStaticMethodRector',
            [
                'Rector\Renaming\Rector\StaticCall\RenameStaticMethodRector',
                'Rector\Renaming\ValueObject\RenameStaticMethod',
            ],
            sprintf(
                "new RenameStaticMethod('%s', '%s', '%s', '%s')",
                $this->escapePhpString($rule->source->className),
                $this->escapePhpString($rule->source->member ?? ''),
                $this->escapePhpString($rule->target->className),
                $this->escapePhpString($rule->target->member ?? ''),
            ),
        ],
        RectorRuleType::RenameClassConstant => [
            'RenameClassConstFetchRector',
            [
                'Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector',
                'Rector\Renaming\ValueObject\RenameClassAndConstFetch',
            ],
            sprintf(
                "new RenameClassAndConstFetch('%s', '%s', '%s', '%s')",
                $this->escapePhpString($rule->source->className),
                $this->escapePhpString($rule->source->member ?? ''),
                $this->escapePhpString($rule->target->className),
                $this->escapePhpString($rule->target->member ?? ''),
            ),
        ],
        default => ['', [], ''],
    };
}

private function escapePhpString(string $value): string
{
    return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter RectorRuleGeneratorTest`
Expected: OK (14 tests)

**Step 5: Run PHPStan**

Run: `docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M`
Expected: 0 errors

**Step 6: Commit**

```
git add src/Generator/RectorRuleGenerator.php \
        tests/Unit/Generator/RectorRuleGeneratorTest.php
git commit -m "Add renderConfig() for rector.php configuration generation"
git push
```

---

### Task 6: Implement RectorRuleGenerator — renderSkeleton() (TDD)

**Files:**
- Modify: `tests/Unit/Generator/RectorRuleGeneratorTest.php`
- Modify: `src/Generator/RectorRuleGenerator.php`

**Step 1: Add failing tests for `renderSkeleton()`**

Append to `RectorRuleGeneratorTest.php`:

```php
#[Test]
public function renderSkeletonProducesValidPhpClass(): void
{
    $rule = new RectorRule(
        RectorRuleType::Skeleton,
        new CodeReference('TYPO3\CMS\Core\Legacy\OldClass', 'doSomething', CodeReferenceType::InstanceMethod),
        null,
        'Deprecation: #99999 - OldClass deprecated',
        'Deprecation-99999-OldClassDeprecated.rst',
    );

    $output = $this->generator->renderSkeleton($rule);

    self::assertStringContainsString('declare(strict_types=1);', $output);
    self::assertStringContainsString('final class OldClassDeprecatedRector extends AbstractRector', $output);
    self::assertStringContainsString('getRuleDefinition', $output);
    self::assertStringContainsString('getNodeTypes', $output);
    self::assertStringContainsString('refactor', $output);
    self::assertStringContainsString('Deprecation: #99999 - OldClass deprecated', $output);
    self::assertStringContainsString('Deprecation-99999-OldClassDeprecated.rst', $output);
    self::assertStringContainsString('MethodCall::class', $output);
    self::assertStringContainsString('TYPO3\CMS\Core\Legacy\OldClass->doSomething', $output);
}

#[Test]
public function renderSkeletonUsesCorrectNodeTypeForStaticMethod(): void
{
    $rule = new RectorRule(
        RectorRuleType::Skeleton,
        new CodeReference('TYPO3\CMS\Core\Utility', 'method', CodeReferenceType::StaticMethod),
        null,
        'Test',
        'Deprecation-11111-Test.rst',
    );

    $output = $this->generator->renderSkeleton($rule);

    self::assertStringContainsString('StaticCall::class', $output);
}

#[Test]
public function renderSkeletonUsesCorrectNodeTypeForClassName(): void
{
    $rule = new RectorRule(
        RectorRuleType::Skeleton,
        new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
        null,
        'Test',
        'Breaking-22222-ClassRemoved.rst',
    );

    $output = $this->generator->renderSkeleton($rule);

    self::assertStringContainsString('FullyQualified::class', $output);
    self::assertStringContainsString('ClassRemovedRector', $output);
}

#[Test]
public function renderSkeletonClassNameDerivedFromFilename(): void
{
    $rule = new RectorRule(
        RectorRuleType::Skeleton,
        new CodeReference('TYPO3\CMS\Core\Foo', null, CodeReferenceType::ClassName),
        null,
        'Test',
        'Deprecation-55555-SomeComplexFeature.rst',
    );

    $output = $this->generator->renderSkeleton($rule);

    self::assertStringContainsString('SomeComplexFeatureRector', $output);
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter RectorRuleGeneratorTest`
Expected: FAIL — method `renderSkeleton` not found

**Step 3: Implement `renderSkeleton()`**

Add constants and method to `RectorRuleGenerator`:

```php
/**
 * Maps CodeReferenceType to PhpParser node class names for skeleton getNodeTypes().
 */
private const NODE_TYPE_MAP = [
    'class_name' => ['Node\Name\FullyQualified', 'FullyQualified'],
    'instance_method' => ['Node\Expr\MethodCall', 'MethodCall'],
    'static_method' => ['Node\Expr\StaticCall', 'StaticCall'],
    'property' => ['Node\Expr\PropertyFetch', 'PropertyFetch'],
    'class_constant' => ['Node\Expr\ClassConstFetch', 'ClassConstFetch'],
];

/**
 * Render a skeleton-type rule as a complete Rector Rule class file.
 */
public function renderSkeleton(RectorRule $rule): string
{
    $className = $this->generateClassName($rule);
    $nodeInfo  = self::NODE_TYPE_MAP[$rule->source->type->value] ?? ['Node', 'Node'];
    $sourceRef = $this->buildSourceComment($rule->source);

    $output = "<?php\n\n";
    $output .= "declare(strict_types=1);\n\n";
    $output .= "namespace App\\Rector\\Generated;\n\n";
    $output .= "use PhpParser\\Node;\n";
    $output .= sprintf("use PhpParser\\%s;\n", $nodeInfo[0]);
    $output .= "use Rector\\Rector\\AbstractRector;\n";
    $output .= "use Symplify\\RuleDocGenerator\\ValueObject\\CodeSample\\CodeSample;\n";
    $output .= "use Symplify\\RuleDocGenerator\\ValueObject\\RuleDefinition;\n\n";
    $output .= sprintf("/**\n * @see %s\n */\n", $rule->rstFilename);
    $output .= sprintf("final class %s extends AbstractRector\n{\n", $className);

    // getRuleDefinition()
    $output .= "    public function getRuleDefinition(): RuleDefinition\n";
    $output .= "    {\n";
    $output .= "        return new RuleDefinition(\n";
    $output .= sprintf("            '%s',\n", $this->escapePhpString($rule->description));
    $output .= "            [\n";
    $output .= "                new CodeSample(\n";
    $output .= "                    '// TODO: Add before code sample',\n";
    $output .= "                    '// TODO: Add after code sample',\n";
    $output .= "                ),\n";
    $output .= "            ],\n";
    $output .= "        );\n";
    $output .= "    }\n\n";

    // getNodeTypes()
    $output .= "    /**\n";
    $output .= "     * @return array<class-string<Node>>\n";
    $output .= "     */\n";
    $output .= "    public function getNodeTypes(): array\n";
    $output .= "    {\n";
    $output .= sprintf("        return [%s::class];\n", $nodeInfo[1]);
    $output .= "    }\n\n";

    // refactor()
    $output .= "    public function refactor(Node \$node): ?Node\n";
    $output .= "    {\n";
    $output .= "        // TODO: Implement refactoring logic\n";
    $output .= sprintf("        // Source: %s\n", $sourceRef);
    $output .= "\n";
    $output .= "        return null;\n";
    $output .= "    }\n";
    $output .= "}\n";

    return $output;
}

/**
 * Derive a Rector class name from the RST filename.
 *
 * Example: "Deprecation-99999-SomeFeature.rst" → "SomeFeatureRector"
 */
private function generateClassName(RectorRule $rule): string
{
    $basename = pathinfo($rule->rstFilename, PATHINFO_FILENAME);
    $name     = preg_replace('/^(?:Deprecation|Breaking|Feature|Important)-\d+-/', '', $basename);

    return $name . 'Rector';
}

/**
 * Build a human-readable source reference comment.
 */
private function buildSourceComment(CodeReference $ref): string
{
    if ($ref->member === null) {
        return $ref->className;
    }

    $separator = match ($ref->type) {
        CodeReferenceType::InstanceMethod,
        CodeReferenceType::Property => '->',
        CodeReferenceType::StaticMethod,
        CodeReferenceType::ClassConstant => '::',
        default => '::',
    };

    $suffix = match ($ref->type) {
        CodeReferenceType::InstanceMethod,
        CodeReferenceType::StaticMethod => '()',
        default => '',
    };

    return $ref->className . $separator . $ref->member . $suffix;
}
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec phpfpm php vendor/bin/phpunit --testsuite Unit --filter RectorRuleGeneratorTest`
Expected: OK (18 tests)

**Step 5: Run all tests + PHPStan**

Run: `docker compose exec phpfpm php vendor/bin/phpunit && docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M`
Expected: All 84 tests pass (66 existing + 18 new), PHPStan 0 errors

**Step 6: Commit**

```
git add src/Generator/RectorRuleGenerator.php \
        tests/Unit/Generator/RectorRuleGeneratorTest.php
git commit -m "Add renderConfig() and renderSkeleton() to RectorRuleGenerator"
git push
```

---

### Task 7: Update MatcherController with Rector routes

**Files:**
- Modify: `src/Controller/MatcherController.php`

**Step 1: Add `RectorRuleGenerator` dependency and update `generate()` action**

Add `RectorRuleGenerator` to the constructor and pass Rector data to the template:

```php
use App\Generator\RectorRuleGenerator;
use App\Dto\RectorRule;

// ... in constructor:
public function __construct(
    private readonly DocumentService $documentService,
    private readonly MatcherConfigGenerator $generator,
    private readonly RectorRuleGenerator $rectorGenerator,
) {
}

// ... update generate() method:
#[Route('/matcher-analysis/generate/{filename}', name: 'matcher_generate', requirements: self::FILENAME_REQUIREMENT)]
public function generate(string $filename): Response
{
    [$doc, $entries, $phpCode] = $this->resolveGeneratedMatcher($filename);

    $rectorRules         = $this->rectorGenerator->generate($doc);
    $rectorConfigRules   = array_values(array_filter($rectorRules, static fn (RectorRule $r): bool => $r->isConfig()));
    $rectorSkeletonRules = array_values(array_filter($rectorRules, static fn (RectorRule $r): bool => !$r->isConfig()));

    return $this->render('matcher/generate.html.twig', [
        'doc'                  => $doc,
        'entries'              => $entries,
        'phpCode'              => $phpCode,
        'rectorConfigRules'    => $rectorConfigRules,
        'rectorConfigPhp'      => count($rectorConfigRules) > 0 ? $this->rectorGenerator->renderConfig($rectorConfigRules) : null,
        'rectorSkeletonRules'  => $rectorSkeletonRules,
        'rectorGenerator'      => $this->rectorGenerator,
    ]);
}
```

Add `use function` imports at the top:

```php
use function array_filter;
use function array_values;
use function count;
```

**Step 2: Add Rector export routes**

```php
#[Route('/matcher-analysis/export-rector-config/{filename}', name: 'rector_export_config', requirements: self::FILENAME_REQUIREMENT)]
public function exportRectorConfig(string $filename): Response
{
    $doc = $this->documentService->findDocumentByFilename($filename);

    if ($doc === null) {
        throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
    }

    $rules   = $this->rectorGenerator->generate($doc);
    $phpCode = $this->rectorGenerator->renderConfig($rules);

    if ($phpCode === '') {
        throw $this->createNotFoundException('No Rector config rules for this document.');
    }

    $disposition = HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        'rector.php',
    );

    $response = new Response($phpCode);
    $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
    $response->headers->set('Content-Disposition', $disposition);

    return $response;
}

#[Route('/matcher-analysis/export-rector-skeleton/{filename}', name: 'rector_export_skeleton', requirements: self::FILENAME_REQUIREMENT)]
public function exportRectorSkeleton(string $filename): Response
{
    $doc = $this->documentService->findDocumentByFilename($filename);

    if ($doc === null) {
        throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
    }

    $rules          = $this->rectorGenerator->generate($doc);
    $skeletonRules  = array_values(array_filter($rules, static fn (RectorRule $r): bool => !$r->isConfig()));

    if ([] === $skeletonRules) {
        throw $this->createNotFoundException('No Rector skeleton rules for this document.');
    }

    // Single skeleton → download as PHP file
    if (count($skeletonRules) === 1) {
        $phpCode     = $this->rectorGenerator->renderSkeleton($skeletonRules[0]);
        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            pathinfo($filename, PATHINFO_FILENAME) . '-rector.php',
        );

        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    // Multiple skeletons → download as ZIP
    $response = new StreamedResponse(function () use ($skeletonRules): void {
        $zip     = new \ZipArchive();
        $tmpFile = tempnam(sys_get_temp_dir(), 'rector_export_');

        if ($tmpFile === false || $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive.');
        }

        foreach ($skeletonRules as $rule) {
            $phpCode  = $this->rectorGenerator->renderSkeleton($rule);
            $zipName  = $this->rectorGenerator->generateClassName($rule) . '.php';
            $zip->addFromString($zipName, $phpCode);
        }

        $zip->close();
        readfile($tmpFile);
        unlink($tmpFile);
    });

    $disposition = HeaderUtils::makeDisposition(
        HeaderUtils::DISPOSITION_ATTACHMENT,
        pathinfo($filename, PATHINFO_FILENAME) . '-rector-skeletons.zip',
    );

    $response->headers->set('Content-Type', 'application/zip');
    $response->headers->set('Content-Disposition', $disposition);

    return $response;
}
```

> **Note:** The `generateClassName()` method must be made `public` in `RectorRuleGenerator` for the ZIP filename. Update visibility from `private` to `public`.

**Step 3: Test routes with curl**

Run:
```bash
docker compose exec phpfpm php bin/console cache:clear
curl -sk -o /dev/null -w "%{http_code}" https://analyzer.nas.lan/matcher-analysis/generate/Breaking-94243-SendUserSessionCookiesAsHash-signedJWT.rst
```
Expected: 200 (page loads without error)

**Step 4: Run PHPStan + all tests**

Run: `docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M && docker compose exec phpfpm php vendor/bin/phpunit`
Expected: 0 errors, all tests pass

**Step 5: Commit**

```
git add src/Controller/MatcherController.php src/Generator/RectorRuleGenerator.php
git commit -m "Wire RectorRuleGenerator into MatcherController with export routes"
git push
```

---

### Task 8: Update generate template with tabs

**Files:**
- Modify: `templates/matcher/generate.html.twig`

**Step 1: Restructure template with Bootstrap tabs**

Replace the `{% block body %}` content in `templates/matcher/generate.html.twig` with tabbed layout. Keep the document header, wrap existing matcher content in a tab pane, and add two new tab panes for Rector:

```twig
{% extends 'base.html.twig' %}

{% block title %}Generieren: {{ doc.title }}{% endblock %}

{% block breadcrumb %}
    <li class="breadcrumb-item"><a href="{{ path('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ path('matcher_analysis') }}">Matcher-Analyse</a></li>
    <li class="breadcrumb-item active">Generieren</li>
{% endblock %}

{% block body %}
{# Document header #}
<div class="mb-4">
    <div class="d-flex align-items-center gap-2 mb-2">
        {% if doc.type.value == 'deprecation' %}
            <span class="badge text-bg-warning">Deprecation</span>
        {% elseif doc.type.value == 'breaking' %}
            <span class="badge text-bg-danger">Breaking</span>
        {% else %}
            <span class="badge text-bg-secondary">{{ doc.type.value }}</span>
        {% endif %}
        <span class="text-muted">#{{ doc.issueId }}</span>
    </div>
    <h1 class="h3 mb-0">Code generieren</h1>
    <p class="text-muted">{{ doc.title }}</p>
</div>

{# Tabs #}
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-matcher" type="button" role="tab">
            <i class="bi bi-puzzle me-1"></i>Matcher-Config
            {% if entries|length > 0 %}
                <span class="badge rounded-pill text-bg-primary ms-1">{{ entries|length }}</span>
            {% endif %}
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rector-config" type="button" role="tab">
            <i class="bi bi-gear me-1"></i>Rector-Config
            {% if rectorConfigRules|length > 0 %}
                <span class="badge rounded-pill text-bg-success ms-1">{{ rectorConfigRules|length }}</span>
            {% endif %}
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rector-skeleton" type="button" role="tab">
            <i class="bi bi-file-earmark-code me-1"></i>Rector-Skeleton
            {% if rectorSkeletonRules|length > 0 %}
                <span class="badge rounded-pill text-bg-secondary ms-1">{{ rectorSkeletonRules|length }}</span>
            {% endif %}
        </button>
    </li>
</ul>

<div class="tab-content">
    {# Tab 1: Matcher Config (existing content) #}
    <div class="tab-pane fade show active" id="tab-matcher" role="tabpanel">
        {% if entries|length > 0 %}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-puzzle me-1"></i>Generierte Matcher-Eintraege
                    <span class="badge rounded-pill text-bg-primary ms-1">{{ entries|length }}</span>
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Matcher-Typ</th>
                            <th>Identifier</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for entry in entries %}
                        <tr>
                            <td><code class="text-primary">{{ entry.matcherType.value }}</code></td>
                            <td><code>{{ entry.identifier }}</code></td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-filetype-php me-1"></i>PHP-Code</h6>
                <a href="{{ path('matcher_export', {filename: doc.filename}) }}" class="btn btn-sm btn-typo3">
                    <i class="bi bi-download me-1"></i>Als PHP herunterladen
                </a>
            </div>
            <div class="card-body p-0">
                <pre class="code-block m-0 rounded-0 rounded-bottom">{{ phpCode }}</pre>
            </div>
        </div>
        {% else %}
        <div class="alert alert-info d-flex align-items-center">
            <i class="bi bi-info-circle me-2 fs-5"></i>
            <div>Keine automatisch generierbaren Matcher fuer dieses Dokument.</div>
        </div>
        {% endif %}
    </div>

    {# Tab 2: Rector Config #}
    <div class="tab-pane fade" id="tab-rector-config" role="tabpanel">
        {% if rectorConfigRules|length > 0 %}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h6 class="mb-0">
                    <i class="bi bi-gear me-1"></i>Rector-Config Regeln
                    <span class="badge rounded-pill text-bg-success ms-1">{{ rectorConfigRules|length }}</span>
                </h6>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Typ</th>
                            <th>Quelle</th>
                            <th>Ziel</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for rule in rectorConfigRules %}
                        <tr>
                            <td><code class="text-success">{{ rule.type.value }}</code></td>
                            <td><code>{{ rule.source.className }}{% if rule.source.member %}::{{ rule.source.member }}{% endif %}</code></td>
                            <td><code>{{ rule.target.className }}{% if rule.target.member %}::{{ rule.target.member }}{% endif %}</code></td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-filetype-php me-1"></i>rector.php</h6>
                <a href="{{ path('rector_export_config', {filename: doc.filename}) }}" class="btn btn-sm btn-typo3">
                    <i class="bi bi-download me-1"></i>Als PHP herunterladen
                </a>
            </div>
            <div class="card-body p-0">
                <pre class="code-block m-0 rounded-0 rounded-bottom">{{ rectorConfigPhp }}</pre>
            </div>
        </div>
        {% else %}
        <div class="alert alert-info d-flex align-items-center">
            <i class="bi bi-info-circle me-2 fs-5"></i>
            <div>Keine automatischen Rector-Config-Regeln erkannt. Die Migration erfordert wahrscheinlich eine komplexere Rule (siehe Rector-Skeleton Tab).</div>
        </div>
        {% endif %}
    </div>

    {# Tab 3: Rector Skeleton #}
    <div class="tab-pane fade" id="tab-rector-skeleton" role="tabpanel">
        {% if rectorSkeletonRules|length > 0 %}
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-muted mb-0">Fuer die folgenden Code-Referenzen wurde kein automatisches Mapping erkannt. Die generierten Skeleton-Klassen enthalten TODO-Markierungen fuer die manuelle Implementierung.</p>
            {% if rectorSkeletonRules|length > 0 %}
            <a href="{{ path('rector_export_skeleton', {filename: doc.filename}) }}" class="btn btn-sm btn-typo3 flex-shrink-0 ms-3">
                <i class="bi bi-download me-1"></i>{% if rectorSkeletonRules|length > 1 %}Alle herunterladen (ZIP){% else %}Als PHP herunterladen{% endif %}
            </a>
            {% endif %}
        </div>
        {% for rule in rectorSkeletonRules %}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent">
                <h6 class="mb-0">
                    <i class="bi bi-file-earmark-code me-1"></i>
                    <code>{{ rule.source.className }}{% if rule.source.member %}{% if rule.source.type.value == 'instance_method' or rule.source.type.value == 'property' %}->{% else %}::{% endif %}{{ rule.source.member }}{% if rule.source.type.value == 'instance_method' or rule.source.type.value == 'static_method' %}(){% endif %}</code>
                </h6>
            </div>
            <div class="card-body p-0">
                <pre class="code-block m-0 rounded-0 rounded-bottom">{{ rectorGenerator.renderSkeleton(rule) }}</pre>
            </div>
        </div>
        {% endfor %}
        {% else %}
        <div class="alert alert-success d-flex align-items-center">
            <i class="bi bi-check-circle me-2 fs-5"></i>
            <div>Alle Code-Referenzen koennen durch Rector-Config-Regeln abgedeckt werden. Keine Skeletons noetig.</div>
        </div>
        {% endif %}
    </div>
</div>

<a href="{{ path('matcher_analysis') }}" class="btn btn-outline-secondary mt-3">
    <i class="bi bi-arrow-left me-1"></i>Zurueck zur Analyse
</a>
{% endblock %}
```

**Step 2: Clear cache and test in browser**

Run:
```bash
docker compose exec phpfpm php bin/console cache:clear
curl -sk -o /dev/null -w "%{http_code}" https://analyzer.nas.lan/matcher-analysis/generate/Breaking-94243-SendUserSessionCookiesAsHash-signedJWT.rst
```
Expected: 200

Also manually verify:
- Tabs render correctly
- Switching tabs works (Bootstrap JS)
- Matcher tab shows existing content
- Rector-Config tab shows rules or info message
- Rector-Skeleton tab shows skeletons or success message

**Step 3: Run all tests + PHPStan**

Run: `docker compose exec phpfpm php vendor/bin/phpunit && docker compose exec phpfpm php vendor/bin/phpstan analyse --no-progress --level max --memory-limit=512M`
Expected: All tests pass, 0 errors

**Step 4: Commit**

```
git add templates/matcher/generate.html.twig
git commit -m "Add Rector-Config and Rector-Skeleton tabs to generate page"
git push
```

---

## Summary

| Task | Files | Tests Added |
|------|-------|-------------|
| 1. DTOs | 3 new | 0 |
| 2. RST Fixtures | 4 new | 0 |
| 3. MigrationMappingExtractor | 2 new | 9 |
| 4. RectorRuleGenerator::generate() | 2 new | 8 |
| 5. RectorRuleGenerator::renderConfig() | 2 modified | 6 |
| 6. RectorRuleGenerator::renderSkeleton() | 2 modified | 4 |
| 7. Controller | 2 modified | 0 |
| 8. Template | 1 modified | 0 |
| **Total** | **10 files** | **27 tests** |

**New files:** `RectorRuleType.php`, `MigrationMapping.php`, `RectorRule.php`, `MigrationMappingExtractor.php`, `RectorRuleGenerator.php`, 4 RST fixtures, 2 test files

**Modified files:** `MatcherController.php`, `generate.html.twig`
