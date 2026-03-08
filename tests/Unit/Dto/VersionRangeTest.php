<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
    public function constructorSetsProperties(): void
    {
        $range = new VersionRange(12, 13);

        self::assertSame(12, $range->sourceVersion);
        self::assertSame(13, $range->targetVersion);
    }

    #[Test]
    public function getLabelReturnsFormattedString(): void
    {
        $range = new VersionRange(12, 13);

        self::assertSame('TYPO3 12 → 13', $range->getLabel());
    }

    #[Test]
    public function getVersionDirectoriesFiltersCorrectly(): void
    {
        $range = new VersionRange(12, 13);

        $directories = ['10.0', '10.1', '11.5', '12.0', '12.4.x', '13.0'];
        $result      = $range->getVersionDirectories($directories);

        self::assertSame(['12.0', '12.4.x', '13.0'], $result);
    }

    #[Test]
    public function getVersionDirectoriesReturnsEmptyForNoMatches(): void
    {
        $range = new VersionRange(12, 13);

        $directories = ['10.0', '11.5'];
        $result      = $range->getVersionDirectories($directories);

        self::assertSame([], $result);
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
    public function getVersionDirectoriesHandlesEmptyArray(): void
    {
        $range  = new VersionRange(12, 13);
        $result = $range->getVersionDirectories([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function getVersionDirectoriesHandlesDotlessDirectoryNames(): void
    {
        $range  = new VersionRange(12, 13);
        $result = $range->getVersionDirectories(['11', '12', '13', '14']);

        self::assertSame(['12', '13'], $result);
    }

    #[Test]
    public function getCacheKeySuffixReturnsUniqueString(): void
    {
        $range12to13 = new VersionRange(12, 13);
        $range11to12 = new VersionRange(11, 12);

        self::assertSame('v12_v13', $range12to13->getCacheKeySuffix());
        self::assertSame('v11_v12', $range11to12->getCacheKeySuffix());
        self::assertNotSame($range12to13->getCacheKeySuffix(), $range11to12->getCacheKeySuffix());
    }
}
