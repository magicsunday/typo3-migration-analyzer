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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScanSourcePathResolver::class)]
final class ScanSourcePathResolverTest extends TestCase
{
    #[Test]
    public function resolveRewritesPathUnderTheConfiguredHostPrefix(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/scan-sources/lsbs/main/app/vendor/netresearch/nrc-club-register',
            $resolver->resolve('/srv/projects/lsbs/main/app/vendor/netresearch/nrc-club-register'),
        );
    }

    #[Test]
    public function resolveRewritesAnExactHostPrefixMatch(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame('/scan-sources', $resolver->resolve('/srv/projects'));
    }

    #[Test]
    public function resolveIgnoresATrailingSlashOnTheConfiguredHostPrefix(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects/', '/scan-sources');

        self::assertSame(
            '/scan-sources/lsbs',
            $resolver->resolve('/srv/projects/lsbs'),
        );
    }

    #[Test]
    public function resolveLeavesAPathAlreadyUnderTheContainerPathUnchanged(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/scan-sources/lsbs/main',
            $resolver->resolve('/scan-sources/lsbs/main'),
        );
    }

    #[Test]
    public function resolveLeavesAPathOutsideTheHostPrefixUnchanged(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/var/www/html/packages/my_extension',
            $resolver->resolve('/var/www/html/packages/my_extension'),
        );
    }

    #[Test]
    public function resolveDoesNotMatchASiblingDirectoryWithTheSamePrefix(): void
    {
        $resolver = new ScanSourcePathResolver('/srv/projects', '/scan-sources');

        self::assertSame(
            '/srv/projects-old/lsbs',
            $resolver->resolve('/srv/projects-old/lsbs'),
        );
    }

    #[Test]
    public function resolveReturnsThePathUnchangedWhenNoHostPathIsConfigured(): void
    {
        $resolver = new ScanSourcePathResolver('', '/scan-sources');

        self::assertSame(
            '/srv/projects/lsbs',
            $resolver->resolve('/srv/projects/lsbs'),
        );
    }
}
