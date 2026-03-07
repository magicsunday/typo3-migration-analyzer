# TYPO3 Migration Analyzer — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Symfony 7.2 Web-App die TYPO3 Deprecation/Breaking-Change RST-Dokumente parst, fehlende Extension-Scanner-Matcher identifiziert und generiert.

**Architecture:** Symfony 7.2 ohne Datenbank — alle Daten werden zur Laufzeit aus RST-Dateien und Matcher-Configs geparsed. Domain-Layer (Parser, Analyzer, Generator) ist framework-agnostisch, Controller/Twig bilden die UI-Schicht.

**Tech Stack:** PHP 8.3+, Symfony 7.2, Twig, Turbo/Stimulus, AssetMapper, PropertyInfo, PHPUnit, PHPStan 2, PHP-CS-Fixer

---

## Task 1: Projekt-Scaffolding

**Files:**
- Create: `composer.json`
- Create: `.php-cs-fixer.dist.php`
- Create: `phpstan.dist.neon`
- Create: `.gitignore`

**Step 1: Symfony-Skeleton erstellen**

```bash
cd ~/projects
rm -rf typo3-migration-analyzer/.git
composer create-project symfony/skeleton:"7.2.*" typo3-migration-analyzer-tmp
cp -r typo3-migration-analyzer-tmp/* typo3-migration-analyzer/
cp typo3-migration-analyzer-tmp/.* typo3-migration-analyzer/ 2>/dev/null || true
rm -rf typo3-migration-analyzer-tmp
cd typo3-migration-analyzer
```

**Step 2: Benötigte Pakete installieren**

Kein `webapp`-Pack — wir brauchen weder Doctrine noch Security/Mailer. Nur das Noetigste:

```bash
cd ~/projects/typo3-migration-analyzer

# Twig + Asset-Pipeline
composer require symfony/twig-bundle symfony/asset-mapper symfony/stimulus-bundle symfony/ux-turbo

# Introspection
composer require symfony/property-info symfony/property-access

# TYPO3 als Datenquelle (nur für Klassen-Reflection + RST/Matcher-Dateien)
composer require typo3/cms-core:"^13.4" typo3/cms-install:"^13.4"

# Dev-Tools
composer require --dev phpunit/phpunit phpstan/phpstan phpstan/phpstan-symfony phpstan/extension-installer friendsofphp/php-cs-fixer
```

**Step 3: PHP-CS-Fixer konfigurieren**

Erstelle `.php-cs-fixer.dist.php`:

```php
<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'trailing_comma_in_multiline' => [
            'elements' => ['arguments', 'arrays', 'match', 'parameters'],
        ],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
    ])
    ->setFinder($finder);
```

**Step 4: PHPStan konfigurieren**

Erstelle `phpstan.dist.neon`:

```neon
parameters:
    level: 8
    paths:
        - src
        - tests
```

**Step 5: .gitignore ergaenzen**

Ergänze in `.gitignore`:

```
.php-cs-fixer.cache
phpstan-baseline.neon
```

**Step 6: Commit**

```bash
git init
git add .
git commit -m "Initial Symfony 7.2 project setup"
```

---

## Task 2: Value Objects — RstDocument und CodeReference

**Files:**
- Create: `src/Dto/RstDocument.php`
- Create: `src/Dto/CodeReference.php`
- Create: `src/Dto/CodeReferenceType.php`
- Create: `src/Dto/DocumentType.php`
- Create: `src/Dto/ScanStatus.php`
- Test: `tests/Unit/Dto/CodeReferenceTest.php`
- Test: `tests/Unit/Dto/RstDocumentTest.php`

**Step 1: Schreibe Tests für CodeReference**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeReferenceTest extends TestCase
{
    #[Test]
    public function createStaticMethodReference(): void
    {
        $ref = CodeReference::fromPhpRole(
            'TYPO3\CMS\Core\Utility\GeneralUtility::hmac()',
        );

        self::assertSame('TYPO3\CMS\Core\Utility\GeneralUtility', $ref->className);
        self::assertSame('hmac', $ref->memberName);
        self::assertSame(CodeReferenceType::StaticMethod, $ref->type);
    }

    #[Test]
    public function createInstanceMethodReference(): void
    {
        $ref = CodeReference::fromPhpRole(
            'TYPO3\CMS\Core\Resource\FileExtensionFilter->filterInlineChildren()',
        );

        self::assertSame('TYPO3\CMS\Core\Resource\FileExtensionFilter', $ref->className);
        self::assertSame('filterInlineChildren', $ref->memberName);
        self::assertSame(CodeReferenceType::InstanceMethod, $ref->type);
    }

    #[Test]
    public function createClassReference(): void
    {
        $ref = CodeReference::fromPhpRole(
            'TYPO3\CMS\Core\Type\Enumeration',
        );

        self::assertSame('TYPO3\CMS\Core\Type\Enumeration', $ref->className);
        self::assertNull($ref->memberName);
        self::assertSame(CodeReferenceType::ClassName, $ref->type);
    }

    #[Test]
    public function createPropertyReference(): void
    {
        $ref = CodeReference::fromPhpRole(
            'TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter->$sourceTypes',
        );

        self::assertSame('TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter', $ref->className);
        self::assertSame('sourceTypes', $ref->memberName);
        self::assertSame(CodeReferenceType::Property, $ref->type);
    }

    #[Test]
    public function createConstantReference(): void
    {
        $ref = CodeReference::fromPhpRole(
            'TYPO3\CMS\Backend\Template\DocumentTemplate::STATUS_ICON_ERROR',
        );

        self::assertSame('TYPO3\CMS\Backend\Template\DocumentTemplate', $ref->className);
        self::assertSame('STATUS_ICON_ERROR', $ref->memberName);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
    }

    #[Test]
    public function returnNullForNonFqcnStrings(): void
    {
        $ref = CodeReference::fromPhpRole('file');

        self::assertNull($ref);
    }

    #[Test]
    public function returnNullForPhpConstants(): void
    {
        $ref = CodeReference::fromPhpRole('E_USER_DEPRECATED');

        self::assertNull($ref);
    }
}
```

**Step 2: Teste, dass die Tests fehlschlagen**

```bash
vendor/bin/phpunit tests/Unit/Dto/CodeReferenceTest.php
```

Expected: FAIL — Klassen existieren nicht.

**Step 3: Implementiere die Enums und CodeReference**

`src/Dto/CodeReferenceType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

enum CodeReferenceType: string
{
    case ClassName = 'class';
    case InstanceMethod = 'instance_method';
    case StaticMethod = 'static_method';
    case Property = 'property';
    case ClassConstant = 'class_constant';
}
```

`src/Dto/DocumentType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

enum DocumentType: string
{
    case Deprecation = 'Deprecation';
    case Breaking = 'Breaking';
    case Feature = 'Feature';
    case Important = 'Important';
}
```

`src/Dto/ScanStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

enum ScanStatus: string
{
    case FullyScanned = 'FullyScanned';
    case PartiallyScanned = 'PartiallyScanned';
    case NotScanned = 'NotScanned';
}
```

`src/Dto/CodeReference.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class CodeReference
{
    public function __construct(
        public string $className,
        public ?string $memberName,
        public CodeReferenceType $type,
    ) {}

    /**
     * Parse a :php: role value into a CodeReference.
     * Returns null if the string is not a FQCN-based reference.
     */
    public static function fromPhpRole(string $value): ?self
    {
        $value = trim($value, '\\');

        // Static method: Class::method()
        if (preg_match('/^([A-Z][\w\\\\]+)::(\w+)\(\)$/', $value, $m)) {
            return new self($m[1], $m[2], CodeReferenceType::StaticMethod);
        }

        // Instance method: Class->method()
        if (preg_match('/^([A-Z][\w\\\\]+)->(\w+)\(\)$/', $value, $m)) {
            return new self($m[1], $m[2], CodeReferenceType::InstanceMethod);
        }

        // Property: Class->$property
        if (preg_match('/^([A-Z][\w\\\\]+)->\$(\w+)$/', $value, $m)) {
            return new self($m[1], $m[2], CodeReferenceType::Property);
        }

        // Class constant: Class::CONSTANT (no parentheses, uppercase)
        if (preg_match('/^([A-Z][\w\\\\]+)::([A-Z_][A-Z0-9_]*)$/', $value, $m)) {
            return new self($m[1], $m[2], CodeReferenceType::ClassConstant);
        }

        // Bare FQCN: at least two namespace segments
        if (preg_match('/^([A-Z][\w\\\\]*\\\\[\w\\\\]+)$/', $value, $m)) {
            return new self($m[1], null, CodeReferenceType::ClassName);
        }

        return null;
    }
}
```

**Step 4: Teste, dass die Tests gruene sind**

```bash
vendor/bin/phpunit tests/Unit/Dto/CodeReferenceTest.php
```

Expected: PASS

**Step 5: Implementiere RstDocument und Test**

`src/Dto/RstDocument.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class RstDocument
{
    /**
     * @param CodeReference[] $codeReferences
     * @param string[]        $indexTags
     */
    public function __construct(
        public DocumentType $type,
        public int $issueId,
        public string $title,
        public string $version,
        public string $description,
        public string $impact,
        public string $migration,
        public array $codeReferences,
        public array $indexTags,
        public ScanStatus $scanStatus,
        public string $filename,
    ) {}
}
```

`tests/Unit/Dto/RstDocumentTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RstDocumentTest extends TestCase
{
    #[Test]
    public function canBeConstructed(): void
    {
        $doc = new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 98479,
            title: 'Deprecated file reference related functionality',
            version: '12.0',
            description: 'Some description',
            impact: 'Some impact',
            migration: 'Some migration',
            codeReferences: [],
            indexTags: ['Backend', 'FAL', 'ext:backend'],
            scanStatus: ScanStatus::PartiallyScanned,
            filename: 'Deprecation-98479-DeprecatedFileReferenceRelatedFunctionality.rst',
        );

        self::assertSame(DocumentType::Deprecation, $doc->type);
        self::assertSame(98479, $doc->issueId);
        self::assertSame(ScanStatus::PartiallyScanned, $doc->scanStatus);
    }
}
```

**Step 6: Tests ausfuehren**

```bash
vendor/bin/phpunit tests/Unit/Dto/
```

Expected: PASS

**Step 7: Commit**

```bash
git add src/Dto/ tests/Unit/Dto/
git commit -m "Add value objects: RstDocument, CodeReference, enums"
```

---

## Task 3: RstParser

**Files:**
- Create: `src/Parser/RstParser.php`
- Create: `tests/Unit/Parser/RstParserTest.php`
- Create: `tests/Fixtures/Rst/Deprecation-99999-TestDeprecation.rst`
- Create: `tests/Fixtures/Rst/Breaking-88888-TestBreaking.rst`

**Step 1: Erstelle Test-Fixtures**

`tests/Fixtures/Rst/Deprecation-99999-TestDeprecation.rst`:

```rst
.. include:: /Includes.rst.txt

.. _deprecation-99999-1234567890:

=============================================================
Deprecation: #99999 - Test method has been deprecated
=============================================================

See :issue:`99999`

Description
===========

The method :php:`\TYPO3\CMS\Core\Utility\TestUtility::oldMethod()` has been
marked as deprecated. Use :php:`\TYPO3\CMS\Core\Utility\NewUtility::newMethod()`
instead.

The class :php:`\TYPO3\CMS\Core\OldClass` is also deprecated.

Impact
======

Calling :php:`\TYPO3\CMS\Core\Utility\TestUtility::oldMethod()` will trigger
a PHP :php:`E_USER_DEPRECATED` level error.

Affected installations
======================

All installations using the deprecated method.

Migration
=========

Replace :php:`\TYPO3\CMS\Core\Utility\TestUtility::oldMethod()` with
:php:`\TYPO3\CMS\Core\Utility\NewUtility::newMethod()`.

.. index:: Backend, PHP-API, FullyScanned, ext:core
```

`tests/Fixtures/Rst/Breaking-88888-TestBreaking.rst`:

```rst
.. include:: /Includes.rst.txt

.. _breaking-88888:

=============================================
Breaking: #88888 - Test class has been removed
=============================================

See :issue:`88888`

Description
===========

The class :php:`\TYPO3\CMS\Core\Removed\OldService` has been removed.

The property :php:`\TYPO3\CMS\Core\DataHandling\DataHandler->$recUpdateAccessCache`
is now protected.

Impact
======

Using the removed class will cause a fatal error.

Affected installations
======================

Extensions using the removed class.

Migration
=========

Use the new :php:`\TYPO3\CMS\Core\New\NewService` class instead.

.. index:: Backend, NotScanned, ext:core
```

**Step 2: Schreibe Tests für RstParser**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\ScanStatus;
use App\Parser\RstParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RstParserTest extends TestCase
{
    private RstParser $parser;

    protected function setUp(): void
    {
        $this->parser = new RstParser();
    }

    #[Test]
    public function parseDeprecationDocument(): void
    {
        $doc = $this->parser->parseFile(
            __DIR__ . '/../../Fixtures/Rst/Deprecation-99999-TestDeprecation.rst',
            '12.0',
        );

        self::assertSame(DocumentType::Deprecation, $doc->type);
        self::assertSame(99999, $doc->issueId);
        self::assertSame('Test method has been deprecated', $doc->title);
        self::assertSame('12.0', $doc->version);
        self::assertSame(ScanStatus::FullyScanned, $doc->scanStatus);
        self::assertStringContainsString('marked as deprecated', $doc->description);
        self::assertStringContainsString('will trigger', $doc->impact);
        self::assertStringContainsString('Replace', $doc->migration);
    }

    #[Test]
    public function extractCodeReferencesFromDeprecation(): void
    {
        $doc = $this->parser->parseFile(
            __DIR__ . '/../../Fixtures/Rst/Deprecation-99999-TestDeprecation.rst',
            '12.0',
        );

        $classNames = array_map(
            static fn ($ref) => $ref->className . ($ref->memberName ? '::' . $ref->memberName : ''),
            $doc->codeReferences,
        );

        self::assertContains('TYPO3\CMS\Core\Utility\TestUtility::oldMethod', $classNames);
        self::assertContains('TYPO3\CMS\Core\Utility\NewUtility::newMethod', $classNames);
        self::assertContains('TYPO3\CMS\Core\OldClass', $classNames);
    }

    #[Test]
    public function parseBreakingDocument(): void
    {
        $doc = $this->parser->parseFile(
            __DIR__ . '/../../Fixtures/Rst/Breaking-88888-TestBreaking.rst',
            '13.0',
        );

        self::assertSame(DocumentType::Breaking, $doc->type);
        self::assertSame(88888, $doc->issueId);
        self::assertSame(ScanStatus::NotScanned, $doc->scanStatus);
        self::assertSame('13.0', $doc->version);
    }

    #[Test]
    public function extractIndexTags(): void
    {
        $doc = $this->parser->parseFile(
            __DIR__ . '/../../Fixtures/Rst/Deprecation-99999-TestDeprecation.rst',
            '12.0',
        );

        self::assertContains('Backend', $doc->indexTags);
        self::assertContains('PHP-API', $doc->indexTags);
        self::assertContains('ext:core', $doc->indexTags);
        // ScanStatus tags are NOT in indexTags — they go to scanStatus
        self::assertNotContains('FullyScanned', $doc->indexTags);
    }

    #[Test]
    public function extractPropertyReference(): void
    {
        $doc = $this->parser->parseFile(
            __DIR__ . '/../../Fixtures/Rst/Breaking-88888-TestBreaking.rst',
            '13.0',
        );

        $properties = array_filter(
            $doc->codeReferences,
            static fn ($ref) => $ref->type === CodeReferenceType::Property,
        );

        self::assertCount(1, $properties);
    }
}
```

**Step 3: Teste, dass die Tests fehlschlagen**

```bash
vendor/bin/phpunit tests/Unit/Parser/RstParserTest.php
```

Expected: FAIL — RstParser existiert nicht.

**Step 4: Implementiere RstParser**

`src/Parser/RstParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Parser;

use App\Dto\CodeReference;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;

final class RstParser
{
    public function parseFile(string $filePath, string $version): RstDocument
    {
        $content = file_get_contents($filePath);
        $filename = basename($filePath);

        return new RstDocument(
            type: $this->extractType($filename),
            issueId: $this->extractIssueId($content),
            title: $this->extractTitle($content),
            version: $version,
            description: $this->extractSection($content, 'Description'),
            impact: $this->extractSection($content, 'Impact'),
            migration: $this->extractSection($content, 'Migration'),
            codeReferences: $this->extractCodeReferences($content),
            indexTags: $this->extractIndexTags($content),
            scanStatus: $this->extractScanStatus($content),
            filename: $filename,
        );
    }

    private function extractType(string $filename): DocumentType
    {
        if (str_starts_with($filename, 'Deprecation-')) {
            return DocumentType::Deprecation;
        }

        if (str_starts_with($filename, 'Breaking-')) {
            return DocumentType::Breaking;
        }

        if (str_starts_with($filename, 'Feature-')) {
            return DocumentType::Feature;
        }

        return DocumentType::Important;
    }

    private function extractIssueId(string $content): int
    {
        if (preg_match('/:issue:`(\d+)`/', $content, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function extractTitle(string $content): string
    {
        // Title format: "Type: #NNNNN - Title text"
        if (preg_match('/^(?:Deprecation|Breaking|Feature|Important):\s+#\d+\s+-\s+(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function extractSection(string $content, string $sectionName): string
    {
        // Match section header followed by === underline, capture until next section or end
        $pattern = '/^' . preg_quote($sectionName, '/') . '\s*\n=+\n(.*?)(?=\n[A-Z][\w\s]*\n=+\n|\n\.\.\s+index::)/s';

        if (preg_match($pattern, $content, $m)) {
            return trim($m[1]);
        }

        // Try case-insensitive match for "Affected installations" vs "Affected Installations"
        $pattern = '/' . preg_quote($sectionName, '/') . '\s*\n=+\n(.*?)(?=\n[A-Z][\w\s]*\n=+\n|\n\.\.\s+index::)/si';

        if (preg_match($pattern, $content, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * @return CodeReference[]
     */
    private function extractCodeReferences(string $content): array
    {
        $references = [];

        if (preg_match_all('/:php:`([^`]+)`/', $content, $matches)) {
            foreach ($matches[1] as $phpRole) {
                $ref = CodeReference::fromPhpRole(ltrim($phpRole, '\\'));

                if ($ref !== null) {
                    $references[] = $ref;
                }
            }
        }

        // Deduplicate by className + memberName
        $seen = [];
        $unique = [];

        foreach ($references as $ref) {
            $key = $ref->className . '::' . ($ref->memberName ?? '');

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $ref;
            }
        }

        return $unique;
    }

    /**
     * @return string[]
     */
    private function extractIndexTags(string $content): array
    {
        if (!preg_match('/^\.\.\s+index::\s+(.+)$/m', $content, $m)) {
            return [];
        }

        $tags = array_map('trim', explode(',', $m[1]));

        // Remove scan status tags — they go into ScanStatus
        return array_values(array_filter(
            $tags,
            static fn (string $tag) => !in_array($tag, ['FullyScanned', 'PartiallyScanned', 'NotScanned'], true),
        ));
    }

    private function extractScanStatus(string $content): ScanStatus
    {
        if (!preg_match('/^\.\.\s+index::\s+(.+)$/m', $content, $m)) {
            return ScanStatus::NotScanned;
        }

        $tags = array_map('trim', explode(',', $m[1]));

        foreach ($tags as $tag) {
            $status = ScanStatus::tryFrom($tag);

            if ($status !== null) {
                return $status;
            }
        }

        return ScanStatus::NotScanned;
    }
}
```

**Step 5: Teste, dass die Tests gruene sind**

```bash
vendor/bin/phpunit tests/Unit/Parser/RstParserTest.php
```

Expected: PASS

**Step 6: Commit**

```bash
git add src/Parser/ tests/Unit/Parser/ tests/Fixtures/
git commit -m "Add RstParser with RST document parsing"
```

---

## Task 4: MatcherConfigParser

**Files:**
- Create: `src/Parser/MatcherConfigParser.php`
- Create: `src/Dto/MatcherEntry.php`
- Create: `src/Dto/MatcherType.php`
- Test: `tests/Unit/Parser/MatcherConfigParserTest.php`

**Step 1: Schreibe Tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Dto\MatcherType;
use App\Parser\MatcherConfigParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MatcherConfigParserTest extends TestCase
{
    #[Test]
    public function parseAllMatcherConfigs(): void
    {
        $parser = new MatcherConfigParser();
        $entries = $parser->parseFromInstalledPackage();

        self::assertNotEmpty($entries);
    }

    #[Test]
    public function entriesContainRestFileReferences(): void
    {
        $parser = new MatcherConfigParser();
        $entries = $parser->parseFromInstalledPackage();

        foreach ($entries as $entry) {
            self::assertNotEmpty($entry->restFiles, 'Each matcher entry must reference at least one RST file');
        }
    }

    #[Test]
    public function entriesHaveCorrectMatcherTypes(): void
    {
        $parser = new MatcherConfigParser();
        $entries = $parser->parseFromInstalledPackage();

        $types = array_unique(array_map(
            static fn ($e) => $e->matcherType,
            $entries,
        ));

        self::assertContains(MatcherType::MethodCall, $types);
        self::assertContains(MatcherType::ClassName, $types);
    }

    #[Test]
    public function groupByRestFile(): void
    {
        $parser = new MatcherConfigParser();
        $entries = $parser->parseFromInstalledPackage();
        $grouped = $parser->groupByRestFile($entries);

        self::assertIsArray($grouped);
        // Each key should be a .rst filename
        foreach (array_keys($grouped) as $key) {
            self::assertStringEndsWith('.rst', $key);
        }
    }
}
```

**Step 2: Teste, dass die Tests fehlschlagen**

```bash
vendor/bin/phpunit tests/Unit/Parser/MatcherConfigParserTest.php
```

**Step 3: Implementiere MatcherType Enum und MatcherEntry**

`src/Dto/MatcherType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

enum MatcherType: string
{
    case MethodCall = 'MethodCallMatcher';
    case MethodCallStatic = 'MethodCallStaticMatcher';
    case ClassName = 'ClassNameMatcher';
    case ClassConstant = 'ClassConstantMatcher';
    case Constant = 'ConstantMatcher';
    case PropertyProtected = 'PropertyProtectedMatcher';
    case PropertyPublic = 'PropertyPublicMatcher';
    case FunctionCall = 'FunctionCallMatcher';
    case MethodArgumentDropped = 'MethodArgumentDroppedMatcher';
    case MethodArgumentRequired = 'MethodArgumentRequiredMatcher';
    case MethodArgumentUnused = 'MethodArgumentUnusedMatcher';
    case ArrayDimension = 'ArrayDimensionMatcher';
    case InterfaceMethodChanged = 'InterfaceMethodChangedMatcher';
    case PropertyExistsStatic = 'PropertyExistsStaticMatcher';
}
```

`src/Dto/MatcherEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class MatcherEntry
{
    /**
     * @param string[] $restFiles
     * @param array<string, mixed> $additionalConfig
     */
    public function __construct(
        public string $identifier,
        public MatcherType $matcherType,
        public array $restFiles,
        public array $additionalConfig = [],
    ) {}
}
```

`src/Parser/MatcherConfigParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Parser;

use App\Dto\MatcherEntry;
use App\Dto\MatcherType;

final class MatcherConfigParser
{
    /**
     * @return MatcherEntry[]
     */
    public function parseFromInstalledPackage(): array
    {
        $configDir = $this->findConfigDirectory();

        if ($configDir === null) {
            return [];
        }

        $entries = [];

        foreach (MatcherType::cases() as $type) {
            $file = $configDir . '/' . $type->value . '.php';

            if (!is_file($file)) {
                continue;
            }

            $config = include $file;

            if (!is_array($config)) {
                continue;
            }

            foreach ($config as $identifier => $definition) {
                $restFiles = $definition['restFiles'] ?? [];
                unset($definition['restFiles']);

                $entries[] = new MatcherEntry(
                    identifier: $identifier,
                    matcherType: $type,
                    restFiles: $restFiles,
                    additionalConfig: $definition,
                );
            }
        }

        return $entries;
    }

    /**
     * @param MatcherEntry[] $entries
     *
     * @return array<string, MatcherEntry[]>
     */
    public function groupByRestFile(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            foreach ($entry->restFiles as $restFile) {
                $grouped[$restFile][] = $entry;
            }
        }

        return $grouped;
    }

    private function findConfigDirectory(): ?string
    {
        // Standard Composer vendor location
        $candidates = [
            dirname(__DIR__, 2) . '/vendor/typo3/cms-install/Configuration/ExtensionScanner/Php',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }
}
```

**Step 4: Tests ausfuehren**

```bash
vendor/bin/phpunit tests/Unit/Parser/MatcherConfigParserTest.php
```

Expected: PASS

**Step 5: Commit**

```bash
git add src/Parser/MatcherConfigParser.php src/Dto/MatcherType.php src/Dto/MatcherEntry.php tests/Unit/Parser/MatcherConfigParserTest.php
git commit -m "Add MatcherConfigParser for reading existing matcher configs"
```

---

## Task 5: RstFileLocator — RST-Dateien finden und parsen

**Files:**
- Create: `src/Parser/RstFileLocator.php`
- Test: `tests/Unit/Parser/RstFileLocatorTest.php`

**Step 1: Schreibe Tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Dto\DocumentType;
use App\Parser\RstFileLocator;
use App\Parser\RstParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RstFileLocatorTest extends TestCase
{
    #[Test]
    public function findAllDocumentsForVersionRange(): void
    {
        $locator = new RstFileLocator(new RstParser());
        $docs = $locator->findAll(['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x']);

        self::assertNotEmpty($docs);
    }

    #[Test]
    public function filterByType(): void
    {
        $locator = new RstFileLocator(new RstParser());
        $docs = $locator->findAll(['13.0']);

        $deprecations = array_filter(
            $docs,
            static fn ($d) => $d->type === DocumentType::Deprecation,
        );

        $breaking = array_filter(
            $docs,
            static fn ($d) => $d->type === DocumentType::Breaking,
        );

        self::assertNotEmpty($deprecations);
        self::assertNotEmpty($breaking);
    }
}
```

**Step 2: Implementiere RstFileLocator**

```php
<?php

declare(strict_types=1);

namespace App\Parser;

use App\Dto\RstDocument;
use Symfony\Component\Finder\Finder;

final readonly class RstFileLocator
{
    public function __construct(
        private RstParser $parser,
    ) {}

    /**
     * @param string[] $versions e.g. ['12.0', '12.1', '13.0']
     *
     * @return RstDocument[]
     */
    public function findAll(array $versions): array
    {
        $baseDir = $this->findChangelogDirectory();

        if ($baseDir === null) {
            return [];
        }

        $documents = [];

        foreach ($versions as $version) {
            $versionDir = $baseDir . '/' . $version;

            if (!is_dir($versionDir)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->in($versionDir)
                ->name('/^(Deprecation|Breaking)-\d+.*\.rst$/')
                ->sortByName();

            foreach ($finder as $file) {
                $documents[] = $this->parser->parseFile(
                    $file->getRealPath(),
                    $version,
                );
            }
        }

        return $documents;
    }

    private function findChangelogDirectory(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/vendor/typo3/cms-core/Documentation/Changelog',
        ];

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }
}
```

**Step 3: Tests ausfuehren und committen**

```bash
vendor/bin/phpunit tests/Unit/Parser/RstFileLocatorTest.php
git add src/Parser/RstFileLocator.php tests/Unit/Parser/RstFileLocatorTest.php
git commit -m "Add RstFileLocator to discover and parse RST files by version"
```

---

## Task 6: MatcherCoverageAnalyzer

**Files:**
- Create: `src/Analyzer/MatcherCoverageAnalyzer.php`
- Create: `src/Dto/CoverageResult.php`
- Test: `tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php`

**Step 1: Schreibe Tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MatcherCoverageAnalyzerTest extends TestCase
{
    #[Test]
    public function detectCoveredDocument(): void
    {
        $analyzer = new MatcherCoverageAnalyzer();

        $docs = [
            new RstDocument(
                type: DocumentType::Deprecation,
                issueId: 12345,
                title: 'Test',
                version: '12.0',
                description: '',
                impact: '',
                migration: '',
                codeReferences: [
                    new CodeReference('TYPO3\CMS\Core\OldClass', 'oldMethod', CodeReferenceType::StaticMethod),
                ],
                indexTags: ['PHP-API', 'ext:core'],
                scanStatus: ScanStatus::FullyScanned,
                filename: 'Deprecation-12345-Test.rst',
            ),
        ];

        $matchers = [
            new MatcherEntry(
                identifier: 'TYPO3\CMS\Core\OldClass::oldMethod',
                matcherType: MatcherType::MethodCallStatic,
                restFiles: ['Deprecation-12345-Test.rst'],
            ),
        ];

        $result = $analyzer->analyze($docs, $matchers);

        self::assertCount(1, $result->covered);
        self::assertCount(0, $result->uncovered);
    }

    #[Test]
    public function detectUncoveredDocument(): void
    {
        $analyzer = new MatcherCoverageAnalyzer();

        $docs = [
            new RstDocument(
                type: DocumentType::Deprecation,
                issueId: 99999,
                title: 'No Matcher',
                version: '12.0',
                description: '',
                impact: '',
                migration: '',
                codeReferences: [
                    new CodeReference('TYPO3\CMS\Core\Missing', null, CodeReferenceType::ClassName),
                ],
                indexTags: ['PHP-API', 'ext:core'],
                scanStatus: ScanStatus::NotScanned,
                filename: 'Deprecation-99999-NoMatcher.rst',
            ),
        ];

        $result = $analyzer->analyze($docs, []);

        self::assertCount(0, $result->covered);
        self::assertCount(1, $result->uncovered);
    }

    #[Test]
    public function calculateCoveragePercentage(): void
    {
        $analyzer = new MatcherCoverageAnalyzer();

        $docs = [
            new RstDocument(
                type: DocumentType::Deprecation,
                issueId: 1,
                title: 'Covered',
                version: '12.0',
                description: '',
                impact: '',
                migration: '',
                codeReferences: [],
                indexTags: [],
                scanStatus: ScanStatus::FullyScanned,
                filename: 'Deprecation-1-Covered.rst',
            ),
            new RstDocument(
                type: DocumentType::Deprecation,
                issueId: 2,
                title: 'Uncovered',
                version: '12.0',
                description: '',
                impact: '',
                migration: '',
                codeReferences: [],
                indexTags: [],
                scanStatus: ScanStatus::NotScanned,
                filename: 'Deprecation-2-Uncovered.rst',
            ),
        ];

        $matchers = [
            new MatcherEntry(
                identifier: 'something',
                matcherType: MatcherType::ClassName,
                restFiles: ['Deprecation-1-Covered.rst'],
            ),
        ];

        $result = $analyzer->analyze($docs, $matchers);

        self::assertSame(50.0, $result->coveragePercent);
    }
}
```

**Step 2: Implementiere CoverageResult und MatcherCoverageAnalyzer**

`src/Dto/CoverageResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class CoverageResult
{
    /**
     * @param RstDocument[] $covered
     * @param RstDocument[] $uncovered
     */
    public function __construct(
        public array $covered,
        public array $uncovered,
        public float $coveragePercent,
        public int $totalDocuments,
        public int $totalMatchers,
    ) {}
}
```

`src/Analyzer/MatcherCoverageAnalyzer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CoverageResult;
use App\Dto\MatcherEntry;
use App\Dto\RstDocument;

final class MatcherCoverageAnalyzer
{
    /**
     * @param RstDocument[]  $documents
     * @param MatcherEntry[] $matchers
     */
    public function analyze(array $documents, array $matchers): CoverageResult
    {
        // Build a set of RST filenames that are referenced by at least one matcher
        $coveredFiles = [];

        foreach ($matchers as $matcher) {
            foreach ($matcher->restFiles as $file) {
                $coveredFiles[$file] = true;
            }
        }

        $covered = [];
        $uncovered = [];

        foreach ($documents as $doc) {
            if (isset($coveredFiles[$doc->filename])) {
                $covered[] = $doc;
            } else {
                $uncovered[] = $doc;
            }
        }

        $total = count($documents);
        $percent = $total > 0 ? round(count($covered) / $total * 100, 1) : 0.0;

        return new CoverageResult(
            covered: $covered,
            uncovered: $uncovered,
            coveragePercent: $percent,
            totalDocuments: $total,
            totalMatchers: count($matchers),
        );
    }
}
```

**Step 3: Tests ausfuehren und committen**

```bash
vendor/bin/phpunit tests/Unit/Analyzer/MatcherCoverageAnalyzerTest.php
git add src/Analyzer/ src/Dto/CoverageResult.php tests/Unit/Analyzer/
git commit -m "Add MatcherCoverageAnalyzer with coverage calculation"
```

---

## Task 7: MatcherConfigGenerator

**Files:**
- Create: `src/Generator/MatcherConfigGenerator.php`
- Test: `tests/Unit/Generator/MatcherConfigGeneratorTest.php`

**Step 1: Schreibe Tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\MatcherType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\MatcherConfigGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MatcherConfigGeneratorTest extends TestCase
{
    private MatcherConfigGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MatcherConfigGenerator();
    }

    #[Test]
    public function generateClassNameMatcherForRemovedClass(): void
    {
        $doc = $this->createDoc([
            new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
        ]);

        $configs = $this->generator->generate($doc);

        self::assertCount(1, $configs);
        self::assertSame(MatcherType::ClassName, $configs[0]->matcherType);
        self::assertSame('TYPO3\CMS\Core\OldClass', $configs[0]->identifier);
    }

    #[Test]
    public function generateMethodCallMatcherForInstanceMethod(): void
    {
        $doc = $this->createDoc([
            new CodeReference('TYPO3\CMS\Core\Foo', 'bar', CodeReferenceType::InstanceMethod),
        ]);

        $configs = $this->generator->generate($doc);

        self::assertCount(1, $configs);
        self::assertSame(MatcherType::MethodCall, $configs[0]->matcherType);
        self::assertSame('TYPO3\CMS\Core\Foo->bar', $configs[0]->identifier);
    }

    #[Test]
    public function generateStaticMethodMatcher(): void
    {
        $doc = $this->createDoc([
            new CodeReference('TYPO3\CMS\Core\Utility\GeneralUtility', 'hmac', CodeReferenceType::StaticMethod),
        ]);

        $configs = $this->generator->generate($doc);

        self::assertSame(MatcherType::MethodCallStatic, $configs[0]->matcherType);
        self::assertSame('TYPO3\CMS\Core\Utility\GeneralUtility::hmac', $configs[0]->identifier);
    }

    #[Test]
    public function generatePropertyProtectedMatcher(): void
    {
        $doc = $this->createDoc([
            new CodeReference('TYPO3\CMS\Core\DataHandling\DataHandler', 'recUpdateAccessCache', CodeReferenceType::Property),
        ]);

        $configs = $this->generator->generate($doc);

        self::assertSame(MatcherType::PropertyProtected, $configs[0]->matcherType);
    }

    #[Test]
    public function generateClassConstantMatcher(): void
    {
        $doc = $this->createDoc([
            new CodeReference('TYPO3\CMS\Backend\Template\DocumentTemplate', 'STATUS_ICON_ERROR', CodeReferenceType::ClassConstant),
        ]);

        $configs = $this->generator->generate($doc);

        self::assertSame(MatcherType::ClassConstant, $configs[0]->matcherType);
    }

    #[Test]
    public function renderAsPhpArray(): void
    {
        $doc = $this->createDoc([
            new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
        ]);

        $configs = $this->generator->generate($doc);
        $php = $this->generator->renderPhp($configs);

        self::assertStringContainsString("'TYPO3\\CMS\\Core\\OldClass'", $php);
        self::assertStringContainsString("'restFiles'", $php);
        self::assertStringContainsString('Deprecation-12345-Test.rst', $php);
    }

    /**
     * @param CodeReference[] $refs
     */
    private function createDoc(array $refs): RstDocument
    {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 12345,
            title: 'Test',
            version: '12.0',
            description: '',
            impact: '',
            migration: '',
            codeReferences: $refs,
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-12345-Test.rst',
        );
    }
}
```

**Step 2: Implementiere MatcherConfigGenerator**

`src/Generator/MatcherConfigGenerator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Generator;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;

final class MatcherConfigGenerator
{
    /**
     * @return MatcherEntry[]
     */
    public function generate(RstDocument $document): array
    {
        $entries = [];

        foreach ($document->codeReferences as $ref) {
            $entry = $this->createMatcherEntry($ref, $document);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param MatcherEntry[] $entries
     */
    public function renderPhp(array $entries): string
    {
        $lines = ["<?php\n", "return [\n"];

        foreach ($entries as $entry) {
            $lines[] = sprintf("    '%s' => [\n", addslashes($entry->identifier));

            foreach ($entry->additionalConfig as $key => $value) {
                $lines[] = sprintf("        '%s' => %s,\n", $key, var_export($value, true));
            }

            $lines[] = "        'restFiles' => [\n";

            foreach ($entry->restFiles as $file) {
                $lines[] = sprintf("            '%s',\n", $file);
            }

            $lines[] = "        ],\n";
            $lines[] = "    ],\n";
        }

        $lines[] = "];\n";

        return implode('', $lines);
    }

    private function createMatcherEntry(CodeReference $ref, RstDocument $document): ?MatcherEntry
    {
        $matcherType = $this->determineMatcherType($ref);

        if ($matcherType === null) {
            return null;
        }

        $identifier = $this->buildIdentifier($ref, $matcherType);
        $additionalConfig = $this->buildAdditionalConfig($ref, $matcherType);

        return new MatcherEntry(
            identifier: $identifier,
            matcherType: $matcherType,
            restFiles: [$document->filename],
            additionalConfig: $additionalConfig,
        );
    }

    private function determineMatcherType(CodeReference $ref): ?MatcherType
    {
        return match ($ref->type) {
            CodeReferenceType::ClassName => MatcherType::ClassName,
            CodeReferenceType::InstanceMethod => MatcherType::MethodCall,
            CodeReferenceType::StaticMethod => MatcherType::MethodCallStatic,
            CodeReferenceType::Property => MatcherType::PropertyProtected,
            CodeReferenceType::ClassConstant => MatcherType::ClassConstant,
        };
    }

    private function buildIdentifier(CodeReference $ref, MatcherType $type): string
    {
        if ($ref->memberName === null) {
            return $ref->className;
        }

        $separator = match ($type) {
            MatcherType::MethodCallStatic, MatcherType::ClassConstant => '::',
            default => '->',
        };

        return $ref->className . $separator . $ref->memberName;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdditionalConfig(CodeReference $ref, MatcherType $type): array
    {
        if (in_array($type, [MatcherType::MethodCall, MatcherType::MethodCallStatic], true)) {
            return [
                'numberOfMandatoryArguments' => 0,
                'maximumNumberOfArguments' => 0,
            ];
        }

        return [];
    }
}
```

**Step 3: Tests ausfuehren und committen**

```bash
vendor/bin/phpunit tests/Unit/Generator/MatcherConfigGeneratorTest.php
git add src/Generator/ tests/Unit/Generator/
git commit -m "Add MatcherConfigGenerator for creating matcher configs from RSTs"
```

---

## Task 8: Symfony Service-Wiring

**Files:**
- Modify: `config/services.yaml`

**Step 1: Services konfigurieren**

Symfonys Autowiring erledigt das meiste. Prüfe, dass `config/services.yaml` korrekt ist:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Dto/'
            - '../src/Kernel.php'
```

**Step 2: Smoke-Test**

```bash
php bin/console debug:autowiring App
```

Expected: Alle App-Services werden angezeigt.

**Step 3: Commit**

```bash
git add config/
git commit -m "Configure service autowiring"
```

---

## Task 9: DashboardController + Template

**Files:**
- Create: `src/Controller/DashboardController.php`
- Create: `templates/dashboard/index.html.twig`
- Create: `templates/base.html.twig` (anpassen)
- Modify: `config/routes.yaml`

**Step 1: Erstelle DashboardController**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\DocumentType;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    #[Route('/', name: 'dashboard')]
    public function index(
        RstFileLocator $locator,
        MatcherConfigParser $matcherParser,
        MatcherCoverageAnalyzer $coverageAnalyzer,
    ): Response {
        $documents = $locator->findAll(self::VERSIONS);
        $matchers = $matcherParser->parseFromInstalledPackage();
        $coverage = $coverageAnalyzer->analyze($documents, $matchers);

        $deprecations = array_filter($documents, static fn ($d) => $d->type === DocumentType::Deprecation);
        $breaking = array_filter($documents, static fn ($d) => $d->type === DocumentType::Breaking);

        return $this->render('dashboard/index.html.twig', [
            'totalDocuments' => count($documents),
            'totalDeprecations' => count($deprecations),
            'totalBreaking' => count($breaking),
            'totalMatchers' => count($matchers),
            'coverage' => $coverage,
        ]);
    }
}
```

**Step 2: Erstelle base.html.twig**

Passe `templates/base.html.twig` an:

```twig
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}TYPO3 Migration Analyzer{% endblock %}</title>
    {% block stylesheets %}{% endblock %}
    {% block javascripts %}
        {% block importmap %}{{ importmap('app') }}{% endblock %}
    {% endblock %}
</head>
<body>
    <nav>
        <a href="{{ path('dashboard') }}">Dashboard</a>
        <a href="{{ path('deprecation_list') }}">Deprecations</a>
        <a href="{{ path('matcher_analysis') }}">Matcher-Analyse</a>
    </nav>
    <main>
        {% block body %}{% endblock %}
    </main>
</body>
</html>
```

**Step 3: Erstelle Dashboard-Template**

`templates/dashboard/index.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Dashboard - TYPO3 Migration Analyzer{% endblock %}

{% block body %}
<h1>TYPO3 Migration Analyzer</h1>
<p>TYPO3 12 &rarr; 13 Migration Coverage</p>

<div class="dashboard-grid">
    <div class="card">
        <div class="card-value">{{ totalDocuments }}</div>
        <div class="card-label">RST-Dokumente</div>
    </div>
    <div class="card">
        <div class="card-value">{{ totalDeprecations }}</div>
        <div class="card-label">Deprecations</div>
    </div>
    <div class="card">
        <div class="card-value">{{ totalBreaking }}</div>
        <div class="card-label">Breaking Changes</div>
    </div>
    <div class="card">
        <div class="card-value">{{ totalMatchers }}</div>
        <div class="card-label">Matcher-Eintraege</div>
    </div>
    <div class="card {{ coverage.coveragePercent >= 80 ? 'card--success' : (coverage.coveragePercent >= 50 ? 'card--warning' : 'card--danger') }}">
        <div class="card-value">{{ coverage.coveragePercent }}%</div>
        <div class="card-label">Matcher-Coverage</div>
    </div>
    <div class="card card--danger">
        <div class="card-value">{{ coverage.uncovered|length }}</div>
        <div class="card-label">Ohne Matcher</div>
    </div>
</div>
{% endblock %}
```

**Step 4: CSS erstellen**

`assets/styles/app.css`:

```css
:root {
    --color-bg: #f8f9fa;
    --color-surface: #ffffff;
    --color-primary: #ff8700;
    --color-text: #1a1a2e;
    --color-text-muted: #6c757d;
    --color-success: #198754;
    --color-warning: #ffc107;
    --color-danger: #dc3545;
    --color-border: #dee2e6;
    --radius: 8px;
    --shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--color-bg);
    color: var(--color-text);
    line-height: 1.6;
}

nav {
    background: var(--color-text);
    padding: 1rem 2rem;
    display: flex;
    gap: 2rem;
}

nav a {
    color: #fff;
    text-decoration: none;
    font-weight: 500;
}

nav a:hover { color: var(--color-primary); }

main { padding: 2rem; max-width: 1200px; margin: 0 auto; }

h1 { margin-bottom: 0.25rem; }
h1 + p { color: var(--color-text-muted); margin-bottom: 2rem; }

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.card {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: 1.5rem;
    box-shadow: var(--shadow);
    text-align: center;
}

.card-value { font-size: 2.5rem; font-weight: 700; }
.card-label { color: var(--color-text-muted); font-size: 0.875rem; margin-top: 0.25rem; }

.card--success .card-value { color: var(--color-success); }
.card--warning .card-value { color: var(--color-warning); }
.card--danger .card-value { color: var(--color-danger); }

/* Table styles for list views */
.data-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--color-surface);
    border-radius: var(--radius);
    overflow: hidden;
    box-shadow: var(--shadow);
}

.data-table th {
    background: var(--color-text);
    color: #fff;
    padding: 0.75rem 1rem;
    text-align: left;
    font-weight: 500;
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--color-border);
}

.data-table tr:hover td { background: #f1f3f5; }

.badge {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
}

.badge--deprecation { background: #fff3cd; color: #856404; }
.badge--breaking { background: #f8d7da; color: #842029; }
.badge--scanned { background: #d1e7dd; color: #0f5132; }
.badge--partial { background: #fff3cd; color: #856404; }
.badge--notscanned { background: #f8d7da; color: #842029; }

.filter-bar {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.filter-bar select, .filter-bar input {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    font-size: 0.875rem;
}

.btn {
    display: inline-block;
    padding: 0.5rem 1rem;
    border-radius: var(--radius);
    font-weight: 500;
    text-decoration: none;
    border: none;
    cursor: pointer;
    font-size: 0.875rem;
}

.btn--primary { background: var(--color-primary); color: #fff; }
.btn--primary:hover { background: #e67a00; }

pre {
    background: #1a1a2e;
    color: #e0e0e0;
    padding: 1rem;
    border-radius: var(--radius);
    overflow-x: auto;
    font-size: 0.85rem;
    line-height: 1.5;
}
```

**Step 5: Symfony-Server testen**

```bash
php bin/console server:start
# oder
symfony server:start
```

Oeffne http://localhost:8000 — Dashboard sollte sichtbar sein.

**Step 6: Commit**

```bash
git add src/Controller/DashboardController.php templates/ assets/styles/
git commit -m "Add dashboard with coverage overview"
```

---

## Task 10: DeprecationController + Liste/Detail

**Files:**
- Create: `src/Controller/DeprecationController.php`
- Create: `templates/deprecation/list.html.twig`
- Create: `templates/deprecation/detail.html.twig`

**Step 1: Erstelle DeprecationController**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DocumentType;
use App\Dto\ScanStatus;
use App\Parser\RstFileLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeprecationController extends AbstractController
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    #[Route('/deprecations', name: 'deprecation_list')]
    public function list(Request $request, RstFileLocator $locator): Response
    {
        $documents = $locator->findAll(self::VERSIONS);

        // Filters
        $typeFilter = $request->query->getString('type');
        $versionFilter = $request->query->getString('version');
        $scanFilter = $request->query->getString('scan');
        $search = $request->query->getString('q');

        if ($typeFilter !== '') {
            $type = DocumentType::tryFrom($typeFilter);
            if ($type !== null) {
                $documents = array_filter($documents, static fn ($d) => $d->type === $type);
            }
        }

        if ($versionFilter !== '') {
            $documents = array_filter($documents, static fn ($d) => $d->version === $versionFilter);
        }

        if ($scanFilter !== '') {
            $status = ScanStatus::tryFrom($scanFilter);
            if ($status !== null) {
                $documents = array_filter($documents, static fn ($d) => $d->scanStatus === $status);
            }
        }

        if ($search !== '') {
            $q = mb_strtolower($search);
            $documents = array_filter(
                $documents,
                static fn ($d) => str_contains(mb_strtolower($d->title), $q)
                    || str_contains(mb_strtolower($d->filename), $q),
            );
        }

        return $this->render('deprecation/list.html.twig', [
            'documents' => array_values($documents),
            'versions' => self::VERSIONS,
            'filters' => [
                'type' => $typeFilter,
                'version' => $versionFilter,
                'scan' => $scanFilter,
                'q' => $search,
            ],
        ]);
    }

    #[Route('/deprecations/{filename}', name: 'deprecation_detail')]
    public function detail(string $filename, RstFileLocator $locator): Response
    {
        $documents = $locator->findAll(self::VERSIONS);

        $doc = null;
        foreach ($documents as $d) {
            if ($d->filename === $filename) {
                $doc = $d;
                break;
            }
        }

        if ($doc === null) {
            throw $this->createNotFoundException('Document not found: ' . $filename);
        }

        return $this->render('deprecation/detail.html.twig', [
            'doc' => $doc,
        ]);
    }
}
```

**Step 2: Erstelle List-Template**

`templates/deprecation/list.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Deprecations &amp; Breaking Changes{% endblock %}

{% block body %}
<h1>Deprecations &amp; Breaking Changes</h1>
<p>{{ documents|length }} Dokumente gefunden</p>

<div class="filter-bar">
    <select onchange="location.href=this.value">
        <option value="{{ path('deprecation_list', filters|merge({type: ''})) }}">Alle Typen</option>
        <option value="{{ path('deprecation_list', filters|merge({type: 'Deprecation'})) }}" {{ filters.type == 'Deprecation' ? 'selected' }}>Deprecation</option>
        <option value="{{ path('deprecation_list', filters|merge({type: 'Breaking'})) }}" {{ filters.type == 'Breaking' ? 'selected' }}>Breaking</option>
    </select>
    <select onchange="location.href=this.value">
        <option value="{{ path('deprecation_list', filters|merge({version: ''})) }}">Alle Versionen</option>
        {% for v in versions %}
        <option value="{{ path('deprecation_list', filters|merge({version: v})) }}" {{ filters.version == v ? 'selected' }}>{{ v }}</option>
        {% endfor %}
    </select>
    <select onchange="location.href=this.value">
        <option value="{{ path('deprecation_list', filters|merge({scan: ''})) }}">Alle Scan-Status</option>
        <option value="{{ path('deprecation_list', filters|merge({scan: 'FullyScanned'})) }}" {{ filters.scan == 'FullyScanned' ? 'selected' }}>FullyScanned</option>
        <option value="{{ path('deprecation_list', filters|merge({scan: 'PartiallyScanned'})) }}" {{ filters.scan == 'PartiallyScanned' ? 'selected' }}>PartiallyScanned</option>
        <option value="{{ path('deprecation_list', filters|merge({scan: 'NotScanned'})) }}" {{ filters.scan == 'NotScanned' ? 'selected' }}>NotScanned</option>
    </select>
</div>

<table class="data-table">
    <thead>
        <tr>
            <th>Typ</th>
            <th>Issue</th>
            <th>Titel</th>
            <th>Version</th>
            <th>Scan-Status</th>
            <th>Referenzen</th>
        </tr>
    </thead>
    <tbody>
        {% for doc in documents %}
        <tr>
            <td><span class="badge badge--{{ doc.type.value|lower }}">{{ doc.type.value }}</span></td>
            <td>#{{ doc.issueId }}</td>
            <td><a href="{{ path('deprecation_detail', {filename: doc.filename}) }}">{{ doc.title }}</a></td>
            <td>{{ doc.version }}</td>
            <td>
                <span class="badge badge--{{ doc.scanStatus.value|lower }}">{{ doc.scanStatus.value }}</span>
            </td>
            <td>{{ doc.codeReferences|length }}</td>
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endblock %}
```

**Step 3: Erstelle Detail-Template**

`templates/deprecation/detail.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ doc.type.value }} #{{ doc.issueId }}{% endblock %}

{% block body %}
<h1>{{ doc.type.value }}: #{{ doc.issueId }} - {{ doc.title }}</h1>
<p>
    Version: <strong>{{ doc.version }}</strong> |
    Scan: <span class="badge badge--{{ doc.scanStatus.value|lower }}">{{ doc.scanStatus.value }}</span> |
    Tags: {{ doc.indexTags|join(', ') }}
</p>

<h2>Description</h2>
<pre>{{ doc.description }}</pre>

<h2>Impact</h2>
<pre>{{ doc.impact }}</pre>

<h2>Migration</h2>
<pre>{{ doc.migration }}</pre>

{% if doc.codeReferences|length > 0 %}
<h2>Code-Referenzen</h2>
<table class="data-table">
    <thead>
        <tr>
            <th>Typ</th>
            <th>Klasse</th>
            <th>Member</th>
        </tr>
    </thead>
    <tbody>
        {% for ref in doc.codeReferences %}
        <tr>
            <td><span class="badge">{{ ref.type.value }}</span></td>
            <td><code>{{ ref.className }}</code></td>
            <td>{% if ref.memberName %}<code>{{ ref.memberName }}</code>{% else %}-{% endif %}</td>
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endif %}

<p><a href="{{ path('deprecation_list') }}">&larr; Zurück zur Liste</a></p>
{% endblock %}
```

**Step 4: Testen und committen**

```bash
php bin/console router:match /deprecations
php bin/console router:match /
git add src/Controller/DeprecationController.php templates/deprecation/
git commit -m "Add deprecation list and detail views with filtering"
```

---

## Task 11: MatcherController + Analyse/Generator-UI

**Files:**
- Create: `src/Controller/MatcherController.php`
- Create: `templates/matcher/analysis.html.twig`
- Create: `templates/matcher/generate.html.twig`

**Step 1: Erstelle MatcherController**

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Generator\MatcherConfigGenerator;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MatcherController extends AbstractController
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    #[Route('/matcher', name: 'matcher_analysis')]
    public function analysis(
        RstFileLocator $locator,
        MatcherConfigParser $matcherParser,
        MatcherCoverageAnalyzer $coverageAnalyzer,
    ): Response {
        $documents = $locator->findAll(self::VERSIONS);
        $matchers = $matcherParser->parseFromInstalledPackage();
        $coverage = $coverageAnalyzer->analyze($documents, $matchers);

        return $this->render('matcher/analysis.html.twig', [
            'coverage' => $coverage,
        ]);
    }

    #[Route('/matcher/generate/{filename}', name: 'matcher_generate')]
    public function generate(
        string $filename,
        RstFileLocator $locator,
        MatcherConfigGenerator $generator,
    ): Response {
        $documents = $locator->findAll(self::VERSIONS);

        $doc = null;
        foreach ($documents as $d) {
            if ($d->filename === $filename) {
                $doc = $d;
                break;
            }
        }

        if ($doc === null) {
            throw $this->createNotFoundException('Document not found: ' . $filename);
        }

        $entries = $generator->generate($doc);
        $phpCode = $generator->renderPhp($entries);

        return $this->render('matcher/generate.html.twig', [
            'doc' => $doc,
            'entries' => $entries,
            'phpCode' => $phpCode,
        ]);
    }

    #[Route('/matcher/export/{filename}', name: 'matcher_export')]
    public function export(
        string $filename,
        RstFileLocator $locator,
        MatcherConfigGenerator $generator,
    ): Response {
        $documents = $locator->findAll(self::VERSIONS);

        $doc = null;
        foreach ($documents as $d) {
            if ($d->filename === $filename) {
                $doc = $d;
                break;
            }
        }

        if ($doc === null) {
            throw $this->createNotFoundException();
        }

        $entries = $generator->generate($doc);
        $phpCode = $generator->renderPhp($entries);

        return new Response($phpCode, 200, [
            'Content-Type' => 'application/x-php',
            'Content-Disposition' => 'attachment; filename="' . str_replace('.rst', '.php', $filename) . '"',
        ]);
    }
}
```

**Step 2: Erstelle Analysis-Template**

`templates/matcher/analysis.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Matcher-Analyse{% endblock %}

{% block body %}
<h1>Matcher-Analyse</h1>
<p>Coverage: <strong>{{ coverage.coveragePercent }}%</strong> ({{ coverage.covered|length }}/{{ coverage.totalDocuments }})</p>

<h2>Nicht abgedeckte Dokumente ({{ coverage.uncovered|length }})</h2>

<table class="data-table">
    <thead>
        <tr>
            <th>Typ</th>
            <th>Issue</th>
            <th>Titel</th>
            <th>Version</th>
            <th>Referenzen</th>
            <th>Aktion</th>
        </tr>
    </thead>
    <tbody>
        {% for doc in coverage.uncovered %}
        <tr>
            <td><span class="badge badge--{{ doc.type.value|lower }}">{{ doc.type.value }}</span></td>
            <td>#{{ doc.issueId }}</td>
            <td><a href="{{ path('deprecation_detail', {filename: doc.filename}) }}">{{ doc.title }}</a></td>
            <td>{{ doc.version }}</td>
            <td>{{ doc.codeReferences|length }}</td>
            <td>
                {% if doc.codeReferences|length > 0 %}
                    <a href="{{ path('matcher_generate', {filename: doc.filename}) }}" class="btn btn--primary">Generieren</a>
                {% else %}
                    <span class="badge">Manuell</span>
                {% endif %}
            </td>
        </tr>
        {% endfor %}
    </tbody>
</table>
{% endblock %}
```

**Step 3: Erstelle Generate-Template**

`templates/matcher/generate.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block title %}Matcher generieren: {{ doc.title }}{% endblock %}

{% block body %}
<h1>Matcher generieren</h1>
<p>{{ doc.type.value }}: #{{ doc.issueId }} - {{ doc.title }}</p>

{% if entries|length > 0 %}
<h2>Generierte Matcher-Eintraege ({{ entries|length }})</h2>

<table class="data-table">
    <thead>
        <tr>
            <th>Matcher-Typ</th>
            <th>Identifier</th>
        </tr>
    </thead>
    <tbody>
        {% for entry in entries %}
        <tr>
            <td><code>{{ entry.matcherType.value }}</code></td>
            <td><code>{{ entry.identifier }}</code></td>
        </tr>
        {% endfor %}
    </tbody>
</table>

<h2>PHP-Code</h2>
<pre>{{ phpCode }}</pre>

<a href="{{ path('matcher_export', {filename: doc.filename}) }}" class="btn btn--primary">Als PHP herunterladen</a>
{% else %}
<p>Keine automatisch generierbaren Matcher für dieses Dokument. Manuelle Analyse erforderlich.</p>
{% endif %}

<p><a href="{{ path('matcher_analysis') }}">&larr; Zurück zur Analyse</a></p>
{% endblock %}
```

**Step 4: Testen und committen**

```bash
php bin/console router:match /matcher
git add src/Controller/MatcherController.php templates/matcher/
git commit -m "Add matcher analysis and generator views"
```

---

## Task 12: Caching für Performance

**Files:**
- Create: `src/Service/DocumentCache.php`

Da RST-Parsing bei 346+ Dateien pro Request langsam sein kann, cacche die Ergebnisse.

**Step 1: Erstelle DocumentCache**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\RstDocument;
use App\Parser\RstFileLocator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DocumentCache
{
    public function __construct(
        private RstFileLocator $locator,
        private CacheInterface $cache,
    ) {}

    /**
     * @param string[] $versions
     *
     * @return RstDocument[]
     */
    public function getDocuments(array $versions): array
    {
        $key = 'rst_documents_' . md5(implode(',', $versions));

        return $this->cache->get($key, function (ItemInterface $item) use ($versions): array {
            $item->expiresAfter(3600);

            return $this->locator->findAll($versions);
        });
    }
}
```

**Step 2: Controller auf DocumentCache umstellen**

Ersetze `RstFileLocator` in allen Controllern durch `DocumentCache->getDocuments(self::VERSIONS)`.

**Step 3: Commit**

```bash
git add src/Service/
git commit -m "Add document caching for performance"
```

---

## Task 13: GitHub-Repo erstellen und pushen

**Step 1: Repo auf GitHub erstellen**

```bash
cd ~/projects/typo3-migration-analyzer
gh repo create magicsunday/typo3-migration-analyzer --public --description "Analyzes TYPO3 deprecation RST documents and generates missing Extension Scanner matcher configs" --source=.
```

**Step 2: Pushen**

```bash
git push -u origin main
```

---

## Task 14: Code Quality Checks

**Step 1: PHP-CS-Fixer ausfuehren**

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/php-cs-fixer fix
```

**Step 2: PHPStan ausfuehren**

```bash
vendor/bin/phpstan analyse
```

**Step 3: Alle Tests ausfuehren**

```bash
vendor/bin/phpunit
```

**Step 4: Commit und Push**

```bash
git add -A
git commit -m "Fix code style and type issues"
git push
```

---

## Zusammenfassung der Commits

| # | Commit | Dateien |
|---|--------|---------|
| 1 | Initial Symfony 7.2 project setup | composer.json, configs |
| 2 | Add value objects: RstDocument, CodeReference, enums | src/Dto/, tests/Unit/Dto/ |
| 3 | Add RstParser with RST document parsing | src/Parser/RstParser.php, tests |
| 4 | Add MatcherConfigParser for reading matcher configs | src/Parser/MatcherConfigParser.php |
| 5 | Add RstFileLocator to discover RST files | src/Parser/RstFileLocator.php |
| 6 | Add MatcherCoverageAnalyzer | src/Analyzer/, src/Dto/CoverageResult.php |
| 7 | Add MatcherConfigGenerator | src/Generator/ |
| 8 | Configure service autowiring | config/ |
| 9 | Add dashboard with coverage overview | Controller, templates, CSS |
| 10 | Add deprecation list and detail views | Controller, templates |
| 11 | Add matcher analysis and generator views | Controller, templates |
| 12 | Add document caching for performance | src/Service/ |
| 13 | Create GitHub repo | - |
| 14 | Fix code style and type issues | diverse |
