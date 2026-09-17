<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use InvalidArgumentException;
use RuntimeException;

/**
 * Clones public Git repositories to a temporary directory and cleans them up afterwards.
 */
interface GitRepositoryHandlerInterface
{
    /**
     * Clone a public Git repository to a temporary directory.
     *
     * @param string $url The HTTPS URL of the repository
     *
     * @return string Path to the cloned directory
     *
     * @throws InvalidArgumentException If the URL is invalid
     * @throws RuntimeException         If the clone operation fails
     */
    public function clone(string $url): string;

    /**
     * Remove a previously cloned temporary directory.
     *
     * @throws InvalidArgumentException If the path is outside the temporary directory
     */
    public function cleanup(string $path): void;

    /**
     * Validate that the given URL points to a supported public Git repository.
     *
     * @throws InvalidArgumentException If the URL is not a valid GitHub or GitLab HTTPS URL
     */
    public function validate(string $url): void;
}
