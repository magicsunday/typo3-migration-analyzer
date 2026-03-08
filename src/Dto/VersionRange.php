<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use InvalidArgumentException;

use function array_filter;
use function array_values;
use function sprintf;
use function strstr;

/**
 * Represents a TYPO3 version migration range (e.g. 12 → 13).
 */
final readonly class VersionRange
{
    /**
     * @throws InvalidArgumentException If the source version is greater than or equal to the target version
     */
    public function __construct(
        public int $sourceVersion,
        public int $targetVersion,
    ) {
        if ($sourceVersion >= $targetVersion) {
            throw new InvalidArgumentException(
                sprintf(
                    'Source version (%d) must be less than target version (%d)',
                    $sourceVersion,
                    $targetVersion,
                ),
            );
        }
    }

    /**
     * Returns a human-readable label for this version range.
     */
    public function getLabel(): string
    {
        return sprintf('TYPO3 %d → %d', $this->sourceVersion, $this->targetVersion);
    }

    /**
     * Filters available changelog directory names to those within this version range.
     *
     * Directories are matched by their major version (integer part before the dot),
     * including both source and target version directories.
     *
     * @param string[] $availableDirectories Directory names like ['10.0', '12.4.x', '13.0']
     *
     * @return string[] Filtered directory names within the version range
     */
    public function getVersionDirectories(array $availableDirectories): array
    {
        return array_values(
            array_filter(
                $availableDirectories,
                function (string $directory): bool {
                    $beforeDot    = strstr($directory, '.', true);
                    $majorVersion = (int) ($beforeDot !== false ? $beforeDot : $directory);

                    return $majorVersion >= $this->sourceVersion
                        && $majorVersion <= $this->targetVersion;
                },
            ),
        );
    }

    /**
     * Returns a cache key suffix unique to this version range.
     */
    public function getCacheKeySuffix(): string
    {
        return sprintf('v%d_v%d', $this->sourceVersion, $this->targetVersion);
    }
}
