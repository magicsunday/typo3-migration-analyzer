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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

use function is_dir;
use function mb_strtolower;
use function mkdir;
use function rmdir;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function uniqid;
use function unlink;

/**
 * Handles uploaded ZIP files: validates, extracts to a temporary directory, and cleans up.
 */
final readonly class ZipUploadHandler
{
    /**
     * Default max file size: 50 MB.
     */
    private const int DEFAULT_MAX_FILE_SIZE = 50 * 1024 * 1024;

    /**
     * @param string $tmpDir      Base directory for temporary extraction
     * @param int    $maxFileSize Maximum allowed upload size in bytes
     */
    public function __construct(
        private string $tmpDir,
        private int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE,
    ) {
    }

    /**
     * Validate and extract a ZIP upload to a temporary directory.
     *
     * @return string Path to the extracted directory
     *
     * @throws InvalidArgumentException If the file is not a valid ZIP or exceeds size limits
     * @throws RuntimeException         If extraction fails
     */
    public function extract(UploadedFile $file): string
    {
        $this->validate($file);

        $extractDir = $this->tmpDir . '/upload-' . uniqid('', true);

        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0o755, true);
        }

        mkdir($extractDir, 0o755, true);

        $zip = new ZipArchive();

        if ($zip->open($file->getPathname()) !== true) {
            $this->removeDirectory($extractDir);

            throw new RuntimeException('Failed to open ZIP file.');
        }

        // Validate all entries to prevent path traversal attacks
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entryName = $zip->getNameIndex($i);

            if ($entryName === false || str_contains($entryName, '..')) {
                $zip->close();
                $this->removeDirectory($extractDir);

                throw new InvalidArgumentException(
                    'ZIP file contains invalid path entries.',
                );
            }
        }

        $zip->extractTo($extractDir);
        $zip->close();

        return $extractDir;
    }

    /**
     * Remove a previously extracted temporary directory.
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
     * Validate the uploaded file.
     */
    private function validate(UploadedFile $file): void
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if ($extension !== 'zip') {
            throw new InvalidArgumentException('Only ZIP files are allowed.');
        }

        if ($file->getSize() > $this->maxFileSize) {
            throw new InvalidArgumentException(
                sprintf(
                    'File size (%d bytes) exceeds maximum allowed size (%d bytes).',
                    $file->getSize(),
                    $this->maxFileSize,
                ),
            );
        }
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
