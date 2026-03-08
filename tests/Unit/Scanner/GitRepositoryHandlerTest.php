<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\GitRepositoryHandler;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(GitRepositoryHandler::class)]
final class GitRepositoryHandlerTest extends TestCase
{
    private GitRepositoryHandler $handler;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir  = sys_get_temp_dir() . '/git-repo-test-' . uniqid();
        $this->handler = new GitRepositoryHandler($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    #[Test]
    public function validateAcceptsGitHubUrl(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->validate('https://github.com/vendor/repo');
    }

    #[Test]
    public function validateAcceptsGitLabUrl(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->validate('https://gitlab.com/vendor/repo');
    }

    #[Test]
    public function validateAcceptsUrlWithDotGitSuffix(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->validate('https://github.com/vendor/repo.git');
    }

    #[Test]
    public function validateAcceptsUrlWithSubgroups(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->validate('https://gitlab.com/group/subgroup/repo');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'empty string' => [
                '',
                'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            ],
            'http instead of https' => [
                'http://github.com/vendor/repo',
                'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            ],
            'ssh url' => [
                'git@github.com:vendor/repo.git',
                'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            ],
            'unsupported host' => [
                'https://bitbucket.org/vendor/repo',
                'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            ],
            'missing repo path' => [
                'https://github.com/',
                'Die URL muss mindestens einen Besitzer und ein Repository enthalten.',
            ],
            'only owner without repo' => [
                'https://github.com/vendor',
                'Die URL muss mindestens einen Besitzer und ein Repository enthalten.',
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrlProvider')]
    public function validateRejectsInvalidUrl(string $url, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->handler->validate($url);
    }

    #[Test]
    public function cleanupRefusesPathOutsideTmpDir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the temporary directory');

        $this->handler->cleanup('/etc/passwd');
    }

    #[Test]
    public function cleanupRemovesClonedDirectory(): void
    {
        mkdir($this->tmpDir, 0o755, true);

        $fakeCloneDir = $this->tmpDir . '/clone-fake';
        mkdir($fakeCloneDir, 0o755, true);

        self::assertDirectoryExists($fakeCloneDir);

        $this->handler->cleanup($fakeCloneDir);

        self::assertDirectoryDoesNotExist($fakeCloneDir);
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
