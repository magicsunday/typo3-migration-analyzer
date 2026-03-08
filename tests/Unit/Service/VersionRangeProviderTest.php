<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\VersionRangeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(VersionRangeProvider::class)]
final class VersionRangeProviderTest extends TestCase
{
    #[Test]
    public function getAvailableDirectoriesReturnsNonEmptyList(): void
    {
        $provider    = new VersionRangeProvider();
        $directories = $provider->getAvailableDirectories();

        self::assertNotSame([], $directories);
        self::assertContains('12.0', $directories);
        self::assertContains('13.0', $directories);
    }

    #[Test]
    public function getAvailableMajorVersionsReturnsUniqueIntegers(): void
    {
        $provider      = new VersionRangeProvider();
        $majorVersions = $provider->getAvailableMajorVersions();

        self::assertNotSame([], $majorVersions);
        self::assertContains(12, $majorVersions);
        self::assertContains(13, $majorVersions);

        // Verify ascending sort
        $sorted = $majorVersions;

        self::assertSame($sorted, $majorVersions);

        // Verify uniqueness
        $previousVersion = null;

        foreach ($majorVersions as $version) {
            if ($previousVersion !== null) {
                self::assertGreaterThan($previousVersion, $version);
            }

            $previousVersion = $version;
        }
    }

    #[Test]
    public function getMigrationPathsReturnsPairsOfConsecutiveVersions(): void
    {
        $provider = new VersionRangeProvider();
        $paths    = $provider->getMigrationPaths();

        self::assertNotSame([], $paths);

        foreach ($paths as $path) {
            self::assertSame($path->sourceVersion + 1, $path->targetVersion);
        }
    }

    #[Test]
    public function getDefaultRangeReturnsLatestMigrationPath(): void
    {
        $provider     = new VersionRangeProvider();
        $paths        = $provider->getMigrationPaths();
        $defaultRange = $provider->getDefaultRange();
        $lastPath     = $paths[count($paths) - 1];

        self::assertSame($lastPath->sourceVersion, $defaultRange->sourceVersion);
        self::assertSame($lastPath->targetVersion, $defaultRange->targetVersion);
    }
}
