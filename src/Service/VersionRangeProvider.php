<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\VersionRange;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function dirname;
use function is_dir;
use function preg_match;
use function scandir;
use function sort;

/**
 * Provides available TYPO3 version directories and migration paths.
 */
final readonly class VersionRangeProvider
{
    /**
     * Scans the TYPO3 changelog directory for version subdirectories.
     *
     * @return string[] Sorted list of directory names matching version patterns (e.g. '12.0', '13.4.x')
     */
    public function getAvailableDirectories(): array
    {
        $changelogPath = dirname(__DIR__, 2) . '/vendor/typo3/cms-core/Documentation/Changelog';

        $entries = scandir($changelogPath);

        if ($entries === false) {
            return [];
        }

        $directories = array_filter(
            $entries,
            static fn (string $entry): bool => preg_match('/^\d+\.\d/', $entry) === 1
                && is_dir($changelogPath . '/' . $entry),
        );

        $directories = array_values($directories);

        sort($directories, SORT_NATURAL);

        return $directories;
    }

    /**
     * Extracts unique major version integers from available changelog directories.
     *
     * @return int[] Sorted list of major version numbers (e.g. [7, 8, 9, 10, 11, 12, 13])
     */
    public function getAvailableMajorVersions(): array
    {
        $directories = $this->getAvailableDirectories();

        $majorVersions = array_map(
            static fn (string $directory): int => (int) $directory,
            $directories,
        );

        $majorVersions = array_values(array_unique($majorVersions, SORT_NUMERIC));

        sort($majorVersions, SORT_NUMERIC);

        return $majorVersions;
    }

    /**
     * Builds consecutive migration path pairs from available major versions.
     *
     * @return VersionRange[] List of consecutive version ranges (e.g. [7→8, 8→9, ..., 12→13])
     */
    public function getMigrationPaths(): array
    {
        $majorVersions = $this->getAvailableMajorVersions();
        $paths         = [];

        for ($i = 0, $max = count($majorVersions); $i < $max - 1; ++$i) {
            $paths[] = new VersionRange($majorVersions[$i], $majorVersions[$i + 1]);
        }

        return $paths;
    }

    /**
     * Returns the latest (most recent) migration path.
     *
     * @throws RuntimeException If no migration paths are available
     */
    public function getDefaultRange(): VersionRange
    {
        $paths = $this->getMigrationPaths();

        if ($paths === []) {
            throw new RuntimeException('No migration paths available');
        }

        return $paths[count($paths) - 1];
    }
}
