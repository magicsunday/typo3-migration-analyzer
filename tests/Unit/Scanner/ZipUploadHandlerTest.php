<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\ZipUploadHandler;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

use function file_put_contents;
use function is_dir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(ZipUploadHandler::class)]
final class ZipUploadHandlerTest extends TestCase
{
    private ZipUploadHandler $handler;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/zip-upload-test-' . uniqid();
        $this->handler = new ZipUploadHandler($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    #[Test]
    public function extractCreatesDirectoryAndReturnPath(): void
    {
        $zipPath = $this->createTestZip();
        $file    = new UploadedFile($zipPath, 'test-ext.zip', 'application/zip', null, true);

        $extractedPath = $this->handler->extract($file);

        self::assertDirectoryExists($extractedPath);
    }

    #[Test]
    public function extractRejectsNonZipFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'not-zip-');
        file_put_contents($tmpFile, '<?php echo "hello";');

        $file = new UploadedFile($tmpFile, 'test.php', 'text/plain', null, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only ZIP files are allowed');

        try {
            $this->handler->extract($file);
        } finally {
            unlink($tmpFile);
        }
    }

    #[Test]
    public function extractRejectsOversizedFile(): void
    {
        $zipPath = $this->createTestZip();
        $file    = new UploadedFile($zipPath, 'huge.zip', 'application/zip', null, true);

        $handler = new ZipUploadHandler($this->tmpDir, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');

        $handler->extract($file);
    }

    #[Test]
    public function cleanupRemovesExtractedDirectory(): void
    {
        $zipPath = $this->createTestZip();
        $file    = new UploadedFile($zipPath, 'test-ext.zip', 'application/zip', null, true);

        $extractedPath = $this->handler->extract($file);

        self::assertDirectoryExists($extractedPath);

        $this->handler->cleanup($extractedPath);

        self::assertDirectoryDoesNotExist($extractedPath);
    }

    #[Test]
    public function extractRejectsZipWithPathTraversal(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'traversal-zip-') . '.zip';
        $zip     = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('../../../etc/evil.php', '<?php evil();');
        $zip->close();

        $file = new UploadedFile($zipPath, 'evil.zip', 'application/zip', null, true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid path entries');

        try {
            $this->handler->extract($file);
        } finally {
            unlink($zipPath);
        }
    }

    #[Test]
    public function cleanupRefusesPathOutsideTmpDir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the temporary directory');

        $this->handler->cleanup('/etc/passwd');
    }

    private function createTestZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'test-zip-') . '.zip';
        $zip     = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('Classes/MyClass.php', '<?php class MyClass {}');
        $zip->addFromString('ext_emconf.php', '<?php $EM_CONF = [];');
        $zip->close();

        return $zipPath;
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
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
