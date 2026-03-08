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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

use function array_any;
use function count;
use function explode;
use function is_dir;
use function mkdir;
use function parse_url;
use function realpath;
use function rmdir;
use function sprintf;
use function str_starts_with;
use function trim;
use function uniqid;
use function unlink;

/**
 * Handles cloning of public Git repositories and cleaning up temporary directories.
 */
final readonly class GitRepositoryHandler
{
    /**
     * Default timeout for git clone in seconds.
     */
    private const int DEFAULT_TIMEOUT = 60;

    /**
     * List of allowed Git hosting providers.
     *
     * @var list<string>
     */
    private const array ALLOWED_HOSTS = ['github.com', 'gitlab.com'];

    /**
     * @param string $tmpDir  Base directory for temporary clones
     * @param int    $timeout Timeout for git clone in seconds
     */
    public function __construct(
        private string $tmpDir,
        private int $timeout = self::DEFAULT_TIMEOUT,
    ) {
    }

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
    public function clone(string $url): string
    {
        $this->validate($url);

        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0o755, true);
        }

        $cloneDir = $this->tmpDir . '/clone-' . uniqid('', true);

        $process = new Process(
            ['git', 'clone', '--depth', '1', $url, $cloneDir],
        );

        $process->setTimeout($this->timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->removeDirectory($cloneDir);

            throw new RuntimeException(
                sprintf('Repository konnte nicht geklont werden: %s', $process->getErrorOutput()),
            );
        }

        return $cloneDir;
    }

    /**
     * Remove a previously cloned temporary directory.
     *
     * @throws InvalidArgumentException If the path is outside the temporary directory
     */
    public function cleanup(string $path): void
    {
        $realTmpDir = realpath($this->tmpDir);
        $realPath   = realpath($path);

        if ($realTmpDir === false || $realPath === false || !str_starts_with($realPath, $realTmpDir)) {
            throw new InvalidArgumentException(
                sprintf('Path "%s" is outside the temporary directory.', $path),
            );
        }

        $this->removeDirectory($path);
    }

    /**
     * Validate that the given URL points to a supported public Git repository.
     *
     * @throws InvalidArgumentException If the URL is not a valid GitHub or GitLab HTTPS URL
     */
    public function validate(string $url): void
    {
        $parts = parse_url($url);

        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'], $parts['path'])
            || $parts['scheme'] !== 'https'
            || !$this->isAllowedHost($parts['host'])
        ) {
            throw new InvalidArgumentException(
                'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            );
        }

        $path = trim($parts['path'], '/');
        $path = preg_replace('/\.git$/', '', $path) ?? $path;

        $segments = explode('/', $path);

        // Filter out empty segments
        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        if (count($segments) < 2) {
            throw new InvalidArgumentException(
                'Die URL muss mindestens einen Besitzer und ein Repository enthalten.',
            );
        }
    }

    /**
     * Check whether the given host is in the list of allowed Git hosting providers.
     */
    private function isAllowedHost(string $host): bool
    {
        return array_any(
            self::ALLOWED_HOSTS,
            static fn (string $allowedHost): bool => $allowedHost === $host,
        );
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
