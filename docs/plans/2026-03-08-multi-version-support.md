# Multi-Version Support Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the migration analyzer configurable for any TYPO3 migration path (e.g. 10->11, 11->12, 12->13, 13->14) instead of the current hardcoded 12->13.

**Architecture:** Upgrade typo3/cms-core to ^14.1 so all changelogs 7.x–14.x are available. Add a `VersionRange` DTO representing a source→target migration path. Add `VersionRangeProvider` to detect available versions from vendor and derive valid migration paths. Make `DocumentService` accept a `VersionRange` and scope all data loading + caching to the selected range. Add a migration path selector to the UI that persists in the session. Update all controllers and templates to use the selected range.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5.3, PHPUnit 13

---

## Context

### Current State
- `DocumentService` has a hardcoded `VERSIONS` constant: `['12.0', '12.1', ..., '13.4.x']`
- All controllers call `$documentService->getDocuments()` without version parameters
- Cache keys are static (`rst_documents`, `matcher_entries`, `coverage_result`)
- Sidebar footer shows hardcoded "TYPO3 12 → 13"
- Dashboard shows hardcoded "TYPO3 12 → 13 Migration Coverage"
- `RstFileLocator::findAll(array $versions)` already accepts a version list — no changes needed there

### Key Files
- `src/Service/DocumentService.php` — central data source, hardcoded versions
- `src/Parser/RstFileLocator.php` — already flexible, accepts version array
- `src/Dto/RstDocument.php` — has `version` property, no changes needed
- `src/Dto/CoverageResult.php` — no changes needed
- `src/Analyzer/MatcherCoverageAnalyzer.php` — no changes needed
- `src/Controller/DashboardController.php` — needs version range
- `src/Controller/DeprecationController.php` — needs version range
- `src/Controller/CoverageController.php` — needs version range
- `src/Controller/MatcherController.php` — needs version range
- `templates/base.html.twig:58` — hardcoded "TYPO3 12 → 13"
- `templates/dashboard/index.html.twig:13` — hardcoded "TYPO3 12 → 13"
- `templates/deprecation/list.html.twig:49-52` — version dropdown from `documentService.getVersions()`
- `composer.json:43-44` — typo3/cms-core and typo3/cms-install ^13.4

### TYPO3 Major Version Boundaries
Migration paths are defined by TYPO3 LTS-to-LTS upgrades. The changelogs in between tell you what changed. For "12 → 13" migration, you need the changelog directories for versions 12.0 through 13.4.x (everything introduced after 12 and up to 13 LTS).

Major versions and their minor ranges in vendor:
- 7: 7.0–7.6.x
- 8: 8.0–8.7.x
- 9: 9.0–9.5.x
- 10: 10.0–10.4.x
- 11: 11.0–11.5.x
- 12: 12.0–12.4.x
- 13: 13.0–13.4.x
- 14: 14.0–14.x (after composer upgrade)

### Pre-Commit Rules
- Run `composer ci:cgl` and `composer ci:rector` before every commit
- Run `composer ci:test` — must be green before every commit
- Commit messages in English, no prefix convention, no Co-Authored-By

All `composer` commands run inside the container: `docker compose exec -T phpfpm composer ...`

---

### Task 1: VersionRange DTO

**Files:**
- Create: `src/Dto/VersionRange.php`
- Test: `tests/Unit/Dto/VersionRangeTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\VersionRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionRange::class)]
final class VersionRangeTest extends TestCase
{
    #[Test]
    public function createWithValidRange(): void
    {
        $range = new VersionRange(12, 13);

        self::assertSame(12, $range->sourceVersion);
        self::assertSame(13, $range->targetVersion);
    }

    #[Test]
    public function getLabelReturnsMigrationPath(): void
    {
        $range = new VersionRange(12, 13);

        self::assertSame('TYPO3 12 → 13', $range->getLabel());
    }

    #[Test]
    public function getVersionDirectoriesReturnsExpectedList(): void
    {
        $available = ['10.0', '10.1', '10.2', '10.3', '10.4', '10.4.x',
            '11.0', '11.1', '11.2', '11.3', '11.4', '11.5', '11.5.x',
            '12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x',
            '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

        $range = new VersionRange(12, 13);
        $dirs = $range->getVersionDirectories($available);

        // Should include 12.0-12.4.x and 13.0-13.4.x
        self::assertContains('12.0', $dirs);
        self::assertContains('12.4.x', $dirs);
        self::assertContains('13.0', $dirs);
        self::assertContains('13.4.x', $dirs);
        // Should NOT include 11.x or 10.x
        self::assertNotContains('11.0', $dirs);
        self::assertNotContains('10.4.x', $dirs);
    }

    #[Test]
    public function throwsOnEqualVersions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VersionRange(12, 12);
    }

    #[Test]
    public function throwsOnReversedVersions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VersionRange(13, 12);
    }

    #[Test]
    public function getCacheKeySuffixReturnsUniqueString(): void
    {
        $range = new VersionRange(12, 13);

        self::assertSame('v12_v13', $range->getCacheKeySuffix());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Dto/VersionRangeTest.php -v`
Expected: FAIL — class does not exist

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;

use function sprintf;

/**
 * Represents a TYPO3 migration path from one major version to another.
 */
final readonly class VersionRange
{
    public function __construct(
        public int $sourceVersion,
        public int $targetVersion,
    ) {
        if ($sourceVersion >= $targetVersion) {
            throw new InvalidArgumentException(
                sprintf('Source version (%d) must be less than target version (%d).', $sourceVersion, $targetVersion),
            );
        }
    }

    /**
     * Human-readable label for the migration path.
     */
    public function getLabel(): string
    {
        return sprintf('TYPO3 %d → %d', $this->sourceVersion, $this->targetVersion);
    }

    /**
     * Filter available changelog directories to those relevant for this migration.
     *
     * Includes all directories whose major version matches sourceVersion or targetVersion.
     *
     * @param string[] $availableDirectories All directory names from vendor changelog (e.g. ['10.0', '10.1', ...])
     *
     * @return string[]
     */
    public function getVersionDirectories(array $availableDirectories): array
    {
        return array_values(
            array_filter(
                $availableDirectories,
                function (string $dir): bool {
                    $majorVersion = (int) $dir;

                    return $majorVersion >= $this->sourceVersion
                        && $majorVersion <= $this->targetVersion;
                },
            ),
        );
    }

    /**
     * Cache key suffix to scope cached data to this version range.
     */
    public function getCacheKeySuffix(): string
    {
        return sprintf('v%d_v%d', $this->sourceVersion, $this->targetVersion);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Dto/VersionRangeTest.php -v`
Expected: PASS — all 6 tests green

**Step 5: Run CI and commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Dto/VersionRange.php tests/Unit/Dto/VersionRangeTest.php
git commit -m "Add VersionRange DTO for multi-version migration paths"
```

---

### Task 2: VersionRangeProvider Service

**Files:**
- Create: `src/Service/VersionRangeProvider.php`
- Test: `tests/Unit/Service/VersionRangeProviderTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\VersionRange;
use App\Service\VersionRangeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VersionRangeProvider::class)]
final class VersionRangeProviderTest extends TestCase
{
    #[Test]
    public function getAvailableMajorVersionsReturnsUniqueIntegers(): void
    {
        $provider = new VersionRangeProvider();
        $versions = $provider->getAvailableMajorVersions();

        self::assertNotEmpty($versions);
        // Should contain major versions found in vendor
        self::assertContains(12, $versions);
        self::assertContains(13, $versions);
        // Should be sorted ascending
        self::assertSame($versions, array_values(array_unique($versions)));
        $sorted = $versions;
        sort($sorted);
        self::assertSame($sorted, $versions);
    }

    #[Test]
    public function getMigrationPathsReturnsPairsOfConsecutiveVersions(): void
    {
        $provider = new VersionRangeProvider();
        $paths = $provider->getMigrationPaths();

        self::assertNotEmpty($paths);

        foreach ($paths as $range) {
            self::assertInstanceOf(VersionRange::class, $range);
            self::assertSame($range->sourceVersion + 1, $range->targetVersion);
        }
    }

    #[Test]
    public function getAvailableDirectoriesReturnsNonEmptyList(): void
    {
        $provider = new VersionRangeProvider();
        $dirs = $provider->getAvailableDirectories();

        self::assertNotEmpty($dirs);
        self::assertContains('12.0', $dirs);
        self::assertContains('13.0', $dirs);
    }

    #[Test]
    public function getDefaultRangeReturnsLatestMigrationPath(): void
    {
        $provider = new VersionRangeProvider();
        $default = $provider->getDefaultRange();

        // The default should be the latest available migration path
        $paths = $provider->getMigrationPaths();
        $lastPath = end($paths);

        self::assertSame($lastPath->sourceVersion, $default->sourceVersion);
        self::assertSame($lastPath->targetVersion, $default->targetVersion);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Service/VersionRangeProviderTest.php -v`
Expected: FAIL — class does not exist

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\VersionRange;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function dirname;
use function is_dir;
use function scandir;
use function sort;

use const SORT_NUMERIC;

/**
 * Detects available TYPO3 changelog versions from the vendor directory
 * and derives valid migration paths.
 */
final readonly class VersionRangeProvider
{
    private const string CHANGELOG_BASE_DIR = '/vendor/typo3/cms-core/Documentation/Changelog';

    /**
     * Get all changelog directory names available in vendor.
     *
     * @return string[] e.g. ['10.0', '10.1', ..., '13.4.x']
     */
    public function getAvailableDirectories(): array
    {
        $baseDir = dirname(__DIR__, 2) . self::CHANGELOG_BASE_DIR;

        if (!is_dir($baseDir)) {
            return [];
        }

        $entries = scandir($baseDir);

        if ($entries === false) {
            return [];
        }

        $directories = array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.'
                && $entry !== '..'
                && is_dir($baseDir . '/' . $entry)
                && preg_match('/^\d+\.\d/', $entry) === 1,
        );

        $result = array_values($directories);
        sort($result, SORT_NATURAL);

        return $result;
    }

    /**
     * Extract unique major version numbers from available directories.
     *
     * @return int[] e.g. [7, 8, 9, 10, 11, 12, 13]
     */
    public function getAvailableMajorVersions(): array
    {
        $directories = $this->getAvailableDirectories();

        $majors = array_map(
            static fn (string $dir): int => (int) $dir,
            $directories,
        );

        $unique = array_values(array_unique($majors, SORT_NUMERIC));
        sort($unique, SORT_NUMERIC);

        return $unique;
    }

    /**
     * Derive valid migration paths from consecutive major versions.
     *
     * @return VersionRange[] e.g. [7→8, 8→9, 9→10, 10→11, 11→12, 12→13]
     */
    public function getMigrationPaths(): array
    {
        $majors = $this->getAvailableMajorVersions();
        $paths  = [];

        for ($i = 0, $count = count($majors); $i < $count - 1; $i++) {
            $paths[] = new VersionRange($majors[$i], $majors[$i + 1]);
        }

        return $paths;
    }

    /**
     * Return the latest (most recent) migration path as default.
     */
    public function getDefaultRange(): VersionRange
    {
        $paths = $this->getMigrationPaths();

        return end($paths);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Service/VersionRangeProviderTest.php -v`
Expected: PASS — all 4 tests green

**Step 5: Run CI and commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Service/VersionRangeProvider.php tests/Unit/Service/VersionRangeProviderTest.php
git commit -m "Add VersionRangeProvider to detect available migration paths from vendor"
```

---

### Task 3: Upgrade Composer Dependencies to TYPO3 14

**Files:**
- Modify: `composer.json:43-44`

**Step 1: Upgrade typo3/cms-core and typo3/cms-install**

```bash
docker compose exec -T phpfpm composer require typo3/cms-core:"^14.1" typo3/cms-install:"^14.1"
```

**Step 2: Verify new changelog directories are available**

```bash
docker compose exec -T phpfpm ls /var/www/vendor/typo3/cms-core/Documentation/Changelog/ | sort -V | tail -10
```

Expected: Should now show `14.0`, `14.1` etc.

**Step 3: Run CI to ensure nothing breaks**

```bash
docker compose exec -T phpfpm composer ci:test
```

Expected: All tests pass. If there are compatibility issues with typo3/cms-install ^14.1, investigate and fix.

**Step 4: Commit**

```bash
git add composer.json composer.lock
git commit -m "Upgrade typo3/cms-core and typo3/cms-install to ^14.1 for multi-version changelog access"
```

---

### Task 4: Refactor DocumentService to Accept VersionRange

**Files:**
- Modify: `src/Service/DocumentService.php`
- Modify: `tests/Unit/Service/DocumentServiceTest.php`

**Step 1: Update DocumentServiceTest**

Update tests to reflect the new API where DocumentService uses VersionRangeProvider and accepts an optional VersionRange:

```php
// In DocumentServiceTest, update createServiceWithFixtures:
private function createServiceWithFixtures(?VersionRange $range = null): DocumentService
{
    return new DocumentService(
        new RstFileLocator(new RstParser()),
        new MatcherConfigParser(),
        new MatcherCoverageAnalyzer(),
        new VersionRangeProvider(),
        new ArrayAdapter(),
    );
}

// Add new test:
#[Test]
public function getVersionsReturnsVersionsForCurrentRange(): void
{
    $service = $this->createServiceWithFixtures();
    $versions = $service->getVersions();

    self::assertNotEmpty($versions);
    // Default range (latest) should still contain versions
}

// Add test for getVersionRange:
#[Test]
public function getVersionRangeReturnsDefaultRange(): void
{
    $service = $this->createServiceWithFixtures();
    $range = $service->getVersionRange();

    self::assertInstanceOf(VersionRange::class, $range);
}

#[Test]
public function setVersionRangeChangesLoadedDocuments(): void
{
    $service = $this->createServiceWithFixtures();

    $range11to12 = new VersionRange(11, 12);
    $service->setVersionRange($range11to12);

    $versions = $service->getVersions();

    // Should contain 11.x and 12.x versions
    self::assertTrue(
        array_any($versions, static fn (string $v): bool => str_starts_with($v, '11.')),
    );
    self::assertTrue(
        array_any($versions, static fn (string $v): bool => str_starts_with($v, '12.')),
    );
    // Should NOT contain 13.x
    self::assertFalse(
        array_any($versions, static fn (string $v): bool => str_starts_with($v, '13.')),
    );
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Service/DocumentServiceTest.php -v`
Expected: FAIL — constructor signature mismatch

**Step 3: Refactor DocumentService**

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\CoverageResult;
use App\Dto\MatcherEntry;
use App\Dto\RstDocument;
use App\Dto\VersionRange;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function sprintf;

final class DocumentService
{
    private VersionRange $versionRange;

    public function __construct(
        private readonly RstFileLocator $locator,
        private readonly MatcherConfigParser $matcherParser,
        private readonly MatcherCoverageAnalyzer $coverageAnalyzer,
        private readonly VersionRangeProvider $versionRangeProvider,
        private readonly CacheInterface $cache,
    ) {
        $this->versionRange = $this->versionRangeProvider->getDefaultRange();
    }

    public function getVersionRange(): VersionRange
    {
        return $this->versionRange;
    }

    public function setVersionRange(VersionRange $versionRange): void
    {
        $this->versionRange = $versionRange;
    }

    /**
     * @return string[]
     */
    public function getVersions(): array
    {
        return $this->versionRange->getVersionDirectories(
            $this->versionRangeProvider->getAvailableDirectories(),
        );
    }

    /**
     * @return RstDocument[]
     */
    public function getDocuments(): array
    {
        $cacheKey = sprintf('rst_documents_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->locator->findAll($this->getVersions());
        });
    }

    /**
     * @return MatcherEntry[]
     */
    public function getMatchers(): array
    {
        return $this->cache->get('matcher_entries', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->matcherParser->parseFromInstalledPackage();
        });
    }

    public function getCoverage(): CoverageResult
    {
        $cacheKey = sprintf('coverage_result_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): CoverageResult {
            $item->expiresAfter(3600);

            return $this->coverageAnalyzer->analyze($this->getDocuments(), $this->getMatchers());
        });
    }

    public function findDocumentByFilename(string $filename): ?RstDocument
    {
        return $this->getDocumentIndex()[$filename] ?? null;
    }

    /**
     * @return array<string, RstDocument>
     */
    private function getDocumentIndex(): array
    {
        $cacheKey = sprintf('rst_documents_index_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            $index = [];

            foreach ($this->getDocuments() as $doc) {
                $index[$doc->filename] = $doc;
            }

            return $index;
        });
    }
}
```

Key changes:
- Removed `readonly` from class (because `$versionRange` is mutable)
- Removed hardcoded `VERSIONS` constant
- Added `VersionRangeProvider` dependency (auto-wired by Symfony)
- `getVersions()` now derives versions from the current `VersionRange` + available directories
- Cache keys include version range suffix so different ranges have separate caches
- `getMatchers()` cache key stays global — matchers are version-independent (they come from typo3/cms-install)

**Step 4: Run tests to verify they pass**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Service/DocumentServiceTest.php -v`
Expected: PASS

**Step 5: Run full CI**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
```

Expected: All green. If other tests break due to constructor change, fix them.

**Step 6: Commit**

```bash
git add src/Service/DocumentService.php tests/Unit/Service/DocumentServiceTest.php
git commit -m "Refactor DocumentService to use VersionRange instead of hardcoded version list"
```

---

### Task 5: Add Version Range Session Handling to Controllers

**Files:**
- Modify: `src/Controller/DashboardController.php`
- Modify: `src/Controller/DeprecationController.php`
- Modify: `src/Controller/CoverageController.php`
- Modify: `src/Controller/MatcherController.php`

This task adds a shared mechanism for controllers to read the selected version range from the session and apply it to DocumentService before loading data.

**Step 1: Add a helper trait or base method**

Create an `EventSubscriber` that reads the session and sets the version range on DocumentService before controller actions run. This avoids repeating session logic in every controller.

```php
// src/EventSubscriber/VersionRangeSubscriber.php

<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Dto\VersionRange;
use App\Service\DocumentService;
use App\Service\VersionRangeProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reads the selected migration path from the session and applies it to DocumentService
 * before any controller action runs.
 */
final readonly class VersionRangeSubscriber implements EventSubscriberInterface
{
    private const string SESSION_KEY: string = 'selected_version_range';

    public function __construct(
        private DocumentService $documentService,
        private VersionRangeProvider $versionRangeProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'onController',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        // Check for explicit range change via query parameters
        $source = $request->query->getInt('migration_source');
        $target = $request->query->getInt('migration_target');

        if ($source > 0 && $target > 0 && $source < $target) {
            $range = new VersionRange($source, $target);
            $session->set(self::SESSION_KEY, [
                'source' => $range->sourceVersion,
                'target' => $range->targetVersion,
            ]);
            $this->documentService->setVersionRange($range);

            return;
        }

        // Restore from session
        $stored = $session->get(self::SESSION_KEY);

        if (is_array($stored) && isset($stored['source'], $stored['target'])) {
            $this->documentService->setVersionRange(
                new VersionRange((int) $stored['source'], (int) $stored['target']),
            );
        }
    }
}
```

**Step 2: Write a test for the subscriber**

```php
// tests/Unit/EventSubscriber/VersionRangeSubscriberTest.php

<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Dto\VersionRange;
use App\EventSubscriber\VersionRangeSubscriber;
use App\Service\DocumentService;
use App\Service\VersionRangeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(VersionRangeSubscriber::class)]
final class VersionRangeSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToControllerEvent(): void
    {
        $events = VersionRangeSubscriber::getSubscribedEvents();

        self::assertArrayHasKey('kernel.controller', $events);
    }

    #[Test]
    public function setsVersionRangeFromQueryParameters(): void
    {
        $documentService = $this->createMock(DocumentService::class);
        $provider = $this->createMock(VersionRangeProvider::class);

        $documentService->expects(self::once())
            ->method('setVersionRange')
            ->with(self::callback(
                static fn (VersionRange $range): bool => $range->sourceVersion === 11
                    && $range->targetVersion === 12,
            ));

        $subscriber = new VersionRangeSubscriber($documentService, $provider);

        $request = new Request(['migration_source' => '11', 'migration_target' => '12']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onController($event);
    }

    #[Test]
    public function restoresVersionRangeFromSession(): void
    {
        $documentService = $this->createMock(DocumentService::class);
        $provider = $this->createMock(VersionRangeProvider::class);

        $documentService->expects(self::once())
            ->method('setVersionRange')
            ->with(self::callback(
                static fn (VersionRange $range): bool => $range->sourceVersion === 10
                    && $range->targetVersion === 11,
            ));

        $subscriber = new VersionRangeSubscriber($documentService, $provider);

        $session = new Session(new MockArraySessionStorage());
        $session->set('selected_version_range', ['source' => 10, 'target' => 11]);

        $request = new Request();
        $request->setSession($session);

        $event = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): null => null,
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->onController($event);
    }
}
```

**Step 3: Run tests to verify**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/EventSubscriber/VersionRangeSubscriberTest.php -v`
Expected: PASS after implementation

**Step 4: Run CI and commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/EventSubscriber/VersionRangeSubscriber.php tests/Unit/EventSubscriber/VersionRangeSubscriberTest.php
git commit -m "Add VersionRangeSubscriber to restore migration path from session"
```

---

### Task 6: Add Migration Path Selector to Twig Templates

**Files:**
- Modify: `templates/base.html.twig`
- Modify: `src/Controller/DashboardController.php`
- Modify: `src/Controller/DeprecationController.php`
- Modify: `src/Controller/CoverageController.php`
- Modify: `src/Controller/MatcherController.php`
- Modify: `templates/dashboard/index.html.twig`

**Step 1: Pass version range and migration paths to all templates**

All controllers need to pass `versionRange` and `migrationPaths` to their templates. The cleanest way is via a Twig global variable set by a listener, or by modifying each controller.

Option A (recommended — Twig global): Add a `TwigEventSubscriber` or use `twig.yaml` config with a service.

Option B (simpler): Add to each controller's render call.

Use Option B (KISS — only 4 controllers):

```php
// In DashboardController::index(), add to render params:
'versionRange'   => $documentService->getVersionRange(),
'migrationPaths' => $versionRangeProvider->getMigrationPaths(),

// Same for DeprecationController::list(), CoverageController::index(), MatcherController::analysis()
```

Add `VersionRangeProvider` as a parameter to each controller method or constructor.

**Step 2: Update base.html.twig sidebar footer**

Replace the hardcoded "TYPO3 12 → 13" (line 58) with the dynamic label:

```twig
{# Before: #}
<i class="bi bi-tag me-1"></i>TYPO3 12 &rarr; 13

{# After: #}
<i class="bi bi-tag me-1"></i>{{ versionRange.label|default('TYPO3 12 → 13') }}
```

**Step 3: Add migration path dropdown to base.html.twig navbar**

Add a compact dropdown in the top navbar (after breadcrumb) that lets the user switch migration paths:

```twig
{# In header, after breadcrumb nav #}
{% if migrationPaths is defined and migrationPaths|length > 1 %}
<div class="ms-auto">
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="bi bi-arrow-left-right me-1"></i>{{ versionRange.label }}
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            {% for path in migrationPaths %}
            <li>
                <a class="dropdown-item {{ path.sourceVersion == versionRange.sourceVersion ? 'active' : '' }}"
                   href="?migration_source={{ path.sourceVersion }}&migration_target={{ path.targetVersion }}">
                    {{ path.label }}
                </a>
            </li>
            {% endfor %}
        </ul>
    </div>
</div>
{% endif %}
```

**Step 4: Update dashboard/index.html.twig**

Replace hardcoded "TYPO3 12 → 13 Migration Coverage" (line 13):

```twig
{# Before: #}
<p class="text-muted mb-0">TYPO3 12 &rarr; 13 Migration Coverage</p>

{# After: #}
<p class="text-muted mb-0">{{ versionRange.label }} Migration Coverage</p>
```

**Step 5: Run CI and commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Controller/ templates/
git commit -m "Add migration path selector dropdown and dynamic version labels"
```

---

### Task 7: Update Deprecation List Version Filter

**Files:**
- Modify: `templates/deprecation/list.html.twig`
- Modify: `src/Controller/DeprecationController.php`

**Step 1: Update version filter dropdown**

The deprecation list has a version dropdown (lines 49-52) that currently shows all versions from `documentService.getVersions()`. This now automatically returns only versions for the selected range — no template changes needed for filtering.

However, verify the template still works:

```twig
{# This should already work because getVersions() now returns range-scoped versions #}
<option value="{{ path('deprecation_list', filters|merge({version: ''})) }}">Alle Versionen</option>
{% for v in versions %}
<option value="{{ path('deprecation_list', filters|merge({version: v})) }}" {{ filters.version == v ? 'selected' }}>{{ v }}</option>
{% endfor %}
```

**Step 2: Verify DeprecationController passes necessary data**

Update `DeprecationController::list()` to pass `versionRange` and `migrationPaths`:

```php
#[Route('/deprecations', name: 'deprecation_list')]
public function list(
    Request $request,
    DocumentService $documentService,
    ComplexityScorer $complexityScorer,
    VersionRangeProvider $versionRangeProvider,
): Response {
    // ... existing code ...

    return $this->render('deprecation/list.html.twig', [
        'documents'      => $documents,
        'versions'       => $documentService->getVersions(),
        'filters'        => $filters,
        'scores'         => $scores,
        'versionRange'   => $documentService->getVersionRange(),
        'migrationPaths' => $versionRangeProvider->getMigrationPaths(),
    ]);
}
```

**Step 3: Run CI and commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add src/Controller/DeprecationController.php templates/deprecation/list.html.twig
git commit -m "Scope deprecation list version filter to selected migration path"
```

---

### Task 8: Update Integration Tests

**Files:**
- Modify: `tests/Integration/Parser/RstFileLocatorTest.php`

**Step 1: Update hardcoded version arrays**

The integration test has a hardcoded version array (lines 37-39). Update to be more flexible:

```php
#[Test]
public function findAllDocumentsForVersionRange(): void
{
    $provider = new VersionRangeProvider();
    $range = $provider->getDefaultRange();
    $versions = $range->getVersionDirectories($provider->getAvailableDirectories());

    $documents = $this->locator->findAll($versions);

    self::assertNotEmpty($documents);

    $foundVersions = array_unique(
        array_map(
            static fn (RstDocument $doc): string => $doc->version,
            $documents,
        ),
    );

    self::assertGreaterThanOrEqual(6, count($foundVersions));
}
```

**Step 2: Run CI and commit**

```bash
docker compose exec -T phpfpm composer ci:cgl
docker compose exec -T phpfpm composer ci:rector
docker compose exec -T phpfpm composer ci:test
git add tests/Integration/Parser/RstFileLocatorTest.php
git commit -m "Update integration test to use VersionRangeProvider instead of hardcoded versions"
```

---

### Task 9: Update CLAUDE.md and Roadmap

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/plans/2026-03-07-roadmap-v1.1-v3.0-design.md`

**Step 1: Mark Multi-Version Support as done in CLAUDE.md**

In the roadmap section, change the v2.0 multi-version entry:

```markdown
### v2.0 — Multi-Version + Rector-Integration
- [x] Multi-Version Support (Versions-Bereich konfigurierbar, 9->10 bis 13->14, Session-basiert)
- [ ] Lauffähige Rector-Rules generieren (komplette Rule-Klassen mit Tests)
...
```

Also update the `Bekannte Eigenheiten` section — the TYPO3 dependency version reference and coverage stats may change.

**Step 2: Update the roadmap design doc**

Add implementation details for the multi-version section.

**Step 3: Commit**

```bash
git add CLAUDE.md docs/plans/
git commit -m "Mark multi-version support as complete in roadmap"
```

---

## Summary

| Task | Component | Files Changed |
|------|-----------|---------------|
| 1 | VersionRange DTO | 2 new |
| 2 | VersionRangeProvider | 2 new |
| 3 | Composer upgrade to ^14.1 | composer.json, composer.lock |
| 4 | DocumentService refactor | 2 modified |
| 5 | VersionRangeSubscriber | 2 new |
| 6 | Template migration path selector | 5 modified |
| 7 | Deprecation list version filter | 2 modified |
| 8 | Integration test update | 1 modified |
| 9 | Roadmap update | 2 modified |

Total: ~6 new files, ~10 modified files, 9 commits.
