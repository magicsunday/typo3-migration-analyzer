<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\DirectoryRemover;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(DirectoryRemover::class)]
final class DirectoryRemoverTest extends TestCase
{
    /**
     * A nested tree of directories and files is removed completely.
     */
    #[Test]
    public function removeDeletesTheDirectoryWithAllItsContents(): void
    {
        $root = sys_get_temp_dir() . '/directory_remover_' . uniqid('', true);

        mkdir($root . '/nested/deeper', 0o775, true);
        file_put_contents($root . '/top.txt', 'top');
        file_put_contents($root . '/nested/deeper/leaf.txt', 'leaf');

        DirectoryRemover::remove($root);

        self::assertDirectoryDoesNotExist($root);
    }

    /**
     * A path that does not exist is ignored.
     */
    #[Test]
    public function removeIgnoresAMissingPath(): void
    {
        $path = sys_get_temp_dir() . '/directory_remover_missing_' . uniqid('', true);

        DirectoryRemover::remove($path);

        self::assertFileDoesNotExist($path);
    }

    /**
     * A regular file is not a directory and is left untouched.
     */
    #[Test]
    public function removeLeavesARegularFileUntouched(): void
    {
        $file = sys_get_temp_dir() . '/directory_remover_file_' . uniqid('', true);
        file_put_contents($file, 'content');

        DirectoryRemover::remove($file);

        self::assertFileExists($file);

        unlink($file);
    }
}
