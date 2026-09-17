<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use function realpath;
use function rtrim;
use function str_contains;
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
    /**
     * @param string $scanSourceHostPath      Absolute host path the submitted form value is matched against.
     * @param string $scanSourceContainerPath Container-visible path the matched prefix is rewritten to.
     */
    public function __construct(
        private string $scanSourceHostPath,
        private string $scanSourceContainerPath,
    ) {
    }

    /**
     * Rewrite a path entered on the host to its container-visible equivalent
     * when it falls under the configured host mount source, otherwise return
     * it unchanged. The rewritten path is canonicalized and verified to still
     * reside within the configured container path, rejecting any "../"
     * sequence that would otherwise escape the mounted directory.
     *
     * @param string $path Path as entered in the "Server-Pfad" scan form.
     *
     * @return string The resolved, container-visible path.
     */
    public function resolve(string $path): string
    {
        if (($this->scanSourceHostPath === '') || str_contains($path, "\0")) {
            return $path;
        }

        $hostPrefix = rtrim($this->scanSourceHostPath, '/');

        if (!$this->isWithinOrEqual($path, $hostPrefix)) {
            return $path;
        }

        $rewritten = $path === $hostPrefix
            ? $this->scanSourceContainerPath
            : $this->scanSourceContainerPath . substr($path, strlen($hostPrefix));

        return $this->staysWithinContainerPath($rewritten) ? $rewritten : $path;
    }

    /**
     * Verify that the canonicalized rewritten path still resides within the
     * configured container mount root. A path that cannot be canonicalized
     * (e.g. it does not exist yet) is allowed through, deferring the actual
     * rejection to the caller's own is_dir() check.
     */
    private function staysWithinContainerPath(string $rewritten): bool
    {
        $canonicalMountRoot = realpath($this->scanSourceContainerPath);
        $canonicalRewritten = realpath($rewritten);

        if (($canonicalMountRoot === false) || ($canonicalRewritten === false)) {
            return true;
        }

        return $this->isWithinOrEqual($canonicalRewritten, rtrim($canonicalMountRoot, '/'));
    }

    /**
     * Determine whether the given path equals the given root, or is a
     * descendant of it bounded by a path separator (never matching a sibling
     * directory that merely shares the same string prefix).
     */
    private function isWithinOrEqual(string $path, string $root): bool
    {
        return ($path === $root) || str_starts_with($path, $root . '/');
    }
}
