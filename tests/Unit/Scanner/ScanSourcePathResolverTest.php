<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\ScanSourcePathResolver;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(ScanSourcePathResolver::class)]
final class ScanSourcePathResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/scan-source-resolver-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    /**
     * A path under the configured host prefix is rewritten to its
     * container-visible equivalent.
     */
    #[Test]
    public function resolveRewritesPathUnderTheConfiguredHostPrefix(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/scan-sources/lsbs/main/app/vendor/netresearch/nrc-club-register',
            $resolver->resolve('/srv/projects/lsbs/main/app/vendor/netresearch/nrc-club-register'),
        );
    }

    /**
     * A path exactly matching the configured host prefix is rewritten to the
     * container path itself.
     */
    #[Test]
    public function resolveRewritesAnExactHostPrefixMatch(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame('/scan-sources', $resolver->resolve('/srv/projects'));
    }

    /**
     * A trailing slash on the configured host prefix does not affect the
     * rewrite result.
     */
    #[Test]
    public function resolveIgnoresATrailingSlashOnTheConfiguredHostPrefix(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects/', '/scan-sources');

        self::assertSame(
            '/scan-sources/lsbs',
            $resolver->resolve('/srv/projects/lsbs'),
        );
    }

    /**
     * A path already under the container path is left unchanged.
     */
    #[Test]
    public function resolveLeavesAPathAlreadyUnderTheContainerPathUnchanged(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/scan-sources/lsbs/main',
            $resolver->resolve('/scan-sources/lsbs/main'),
        );
    }

    /**
     * A path outside the configured host prefix is left unchanged.
     */
    #[Test]
    public function resolveLeavesAPathOutsideTheHostPrefixUnchanged(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/var/www/html/packages/my_extension',
            $resolver->resolve('/var/www/html/packages/my_extension'),
        );
    }

    /**
     * A sibling directory sharing the same string prefix, but without a path
     * separator boundary, must not be treated as a prefix match.
     */
    #[Test]
    public function resolveDoesNotMatchASiblingDirectoryWithTheSamePrefix(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/srv/projects-old/lsbs',
            $resolver->resolve('/srv/projects-old/lsbs'),
        );
    }

    /**
     * Rewriting is disabled entirely when no host path is configured.
     */
    #[Test]
    public function resolveReturnsThePathUnchangedWhenNoHostPathIsConfigured(): void
    {
        $resolver = new ScanSourcePathResolver('', '/scan-sources');

        self::assertSame(
            '/srv/projects/lsbs',
            $resolver->resolve('/srv/projects/lsbs'),
        );
    }

    /**
     * A "../" sequence in the submitted path must not be allowed to escape
     * the configured container mount root once canonicalized.
     */
    #[Test]
    public function resolveRejectsATraversalSequenceEscapingTheContainerMountRoot(): void
    {
        $mountRoot = $this->tmpDir . '/allowed';
        $secretDir = $this->tmpDir . '/secret';

        mkdir($mountRoot, 0o755, true);
        mkdir($secretDir, 0o755, true);
        file_put_contents($secretDir . '/secret.php', '<?php');

        $resolver = new ScanSourcePathResolver('/host-prefix', $mountRoot);

        self::assertSame(
            '/host-prefix/../secret',
            $resolver->resolve('/host-prefix/../secret'),
        );
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
