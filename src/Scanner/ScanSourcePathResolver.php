<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Rewrites a host filesystem path entered via the "Server-Pfad" scan form into
 * its container-visible equivalent, based on the configured SCAN_SOURCE_PATH
 * bind mount (see compose.yaml and .env.dist).
 */
final readonly class ScanSourcePathResolver
{
    public function __construct(
        private string $scanSourceHostPath,
        private string $scanSourceContainerPath,
    ) {
    }

    /**
     * Rewrite a path entered on the host to its container-visible equivalent
     * when it falls under the configured host mount source, otherwise return
     * it unchanged.
     */
    public function resolve(string $path): string
    {
        if ($this->scanSourceHostPath === '') {
            return $path;
        }

        $hostPrefix = rtrim($this->scanSourceHostPath, '/');

        if ($path === $hostPrefix) {
            return $this->scanSourceContainerPath;
        }

        if (str_starts_with($path, $hostPrefix . '/')) {
            return $this->scanSourceContainerPath . substr($path, strlen($hostPrefix));
        }

        return $path;
    }
}
