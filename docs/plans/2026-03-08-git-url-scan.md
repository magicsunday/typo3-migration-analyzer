# Git-URL-Scan Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow users to scan public GitHub/GitLab repositories by URL via shallow clone, scan, and automatic cleanup.

**Architecture:** `GitRepositoryHandler` service (analogous to `ZipUploadHandler`) handles URL validation, `git clone --depth 1`, and cleanup. New controller route `POST /scan/clone` with try/finally pattern. Third tab in scan template for URL input.

**Tech Stack:** PHP 8.4, Symfony 7.2, Symfony Process component (`symfony/process`), PHPUnit 12

---

### Task 1: Add symfony/process dependency

**Files:**
- Modify: `composer.json`

**Step 1: Require symfony/process**

Run: `docker compose exec phpfpm composer require symfony/process`

**Step 2: Verify installation**

Run: `docker compose exec phpfpm php -r "echo class_exists('Symfony\Component\Process\Process') ? 'OK' : 'FAIL';"`
Expected: `OK`

**Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "Add symfony/process dependency for git clone support"
```

---

### Task 2: Write GitRepositoryHandler with TDD

**Files:**
- Create: `src/Scanner/GitRepositoryHandler.php`
- Create: `tests/Unit/Scanner/GitRepositoryHandlerTest.php`

**Step 1: Write the failing tests**

```php
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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

#[CoversClass(GitRepositoryHandler::class)]
final class GitRepositoryHandlerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/git-clone-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ))->rewind();

            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function validateAcceptsGitHubUrl(): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        // Should not throw
        $handler->validate('https://github.com/vendor/repo');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateAcceptsGitLabUrl(): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        $handler->validate('https://gitlab.com/vendor/repo');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateAcceptsUrlWithDotGitSuffix(): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        $handler->validate('https://github.com/vendor/repo.git');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function validateAcceptsUrlWithSubgroups(): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        $handler->validate('https://gitlab.com/group/subgroup/repo');
        $this->addToAssertionCount(1);
    }

    #[Test]
    #[DataProvider('invalidUrlProvider')]
    public function validateRejectsInvalidUrl(string $url, string $expectedMessage): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $handler->validate($url);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUrlProvider(): iterable
    {
        yield 'empty string' => ['', 'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.'];
        yield 'http not https' => ['http://github.com/vendor/repo', 'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.'];
        yield 'ssh protocol' => ['git@github.com:vendor/repo.git', 'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.'];
        yield 'bitbucket' => ['https://bitbucket.org/vendor/repo', 'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.'];
        yield 'missing repo path' => ['https://github.com/', 'Die URL muss mindestens einen Besitzer und ein Repository enthalten.'];
        yield 'only owner' => ['https://github.com/vendor', 'Die URL muss mindestens einen Besitzer und ein Repository enthalten.'];
    }

    #[Test]
    public function cleanupRefusesPathOutsideTmpDir(): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the temporary directory');

        $handler->cleanup('/etc/passwd');
    }

    #[Test]
    public function cleanupRemovesClonedDirectory(): void
    {
        $handler = new GitRepositoryHandler($this->tmpDir);

        // Create a fake clone directory
        mkdir($this->tmpDir, 0o755, true);
        $cloneDir = $this->tmpDir . '/repo-test';
        mkdir($cloneDir, 0o755);
        file_put_contents($cloneDir . '/test.php', '<?php');

        $handler->cleanup($cloneDir);

        self::assertDirectoryDoesNotExist($cloneDir);
    }
}
```

**Step 2: Run tests to verify they fail**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Scanner/GitRepositoryHandlerTest.php -v`
Expected: FAIL — class GitRepositoryHandler not found

**Step 3: Write the implementation**

```php
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

use function is_dir;
use function mkdir;
use function parse_url;
use function rmdir;
use function sprintf;
use function str_starts_with;
use function substr_count;
use function trim;
use function uniqid;
use function unlink;

/**
 * Handles cloning public GitHub/GitLab repositories into a temporary directory.
 */
final readonly class GitRepositoryHandler
{
    /**
     * Default clone timeout in seconds.
     */
    private const int DEFAULT_TIMEOUT = 60;

    /**
     * Allowed repository hosts.
     *
     * @var list<string>
     */
    private const array ALLOWED_HOSTS = [
        'github.com',
        'gitlab.com',
    ];

    /**
     * @param string $tmpDir  Base directory for temporary clones
     * @param int    $timeout Clone process timeout in seconds
     */
    public function __construct(
        private string $tmpDir,
        private int $timeout = self::DEFAULT_TIMEOUT,
    ) {
    }

    /**
     * Validate and clone a public repository to a temporary directory.
     *
     * @return string Path to the cloned directory
     *
     * @throws InvalidArgumentException If the URL is invalid
     * @throws RuntimeException         If the clone process fails
     */
    public function clone(string $url): string
    {
        $this->validate($url);

        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0o755, true);
        }

        $cloneDir = $this->tmpDir . '/repo-' . uniqid('', true);

        $process = new Process(
            ['git', 'clone', '--depth', '1', $url, $cloneDir],
        );
        $process->setTimeout($this->timeout);

        $process->run();

        if (!$process->isSuccessful()) {
            // Clean up partial clone
            if (is_dir($cloneDir)) {
                $this->removeDirectory($cloneDir);
            }

            throw new RuntimeException(
                sprintf(
                    'Repository konnte nicht geklont werden: %s',
                    trim($process->getErrorOutput()),
                ),
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
     * Validate that the URL is a supported public repository URL.
     *
     * @throws InvalidArgumentException If the URL is not valid
     */
    public function validate(string $url): void
    {
        $parsed = parse_url($url);

        $scheme = $parsed['scheme'] ?? '';
        $host   = $parsed['host'] ?? '';
        $path   = trim($parsed['path'] ?? '', '/');

        if ($scheme !== 'https' || !$this->isAllowedHost($host)) {
            throw new InvalidArgumentException(
                'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            );
        }

        // Strip .git suffix for path validation
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        // Require at least owner/repo (one slash in path)
        if ($path === '' || substr_count($path, '/') < 1) {
            throw new InvalidArgumentException(
                'Die URL muss mindestens einen Besitzer und ein Repository enthalten.',
            );
        }
    }

    /**
     * Check if the host is in the allowed list.
     */
    private function isAllowedHost(string $host): bool
    {
        return array_any(
            self::ALLOWED_HOSTS,
            static fn (string $allowed): bool => $host === $allowed,
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
```

**Step 4: Run tests to verify they pass**

Run: `docker compose exec phpfpm php vendor/bin/phpunit tests/Unit/Scanner/GitRepositoryHandlerTest.php -v`
Expected: All tests PASS

**Step 5: Run full CI**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 6: Commit**

```bash
git add src/Scanner/GitRepositoryHandler.php tests/Unit/Scanner/GitRepositoryHandlerTest.php
git commit -m "Add GitRepositoryHandler with URL validation and cleanup"
```

---

### Task 3: Add clone controller route

**Files:**
- Modify: `src/Controller/ScanController.php`

**Step 1: Add the clone action**

Add a new method to `ScanController` after `upload()`:

```php
/**
 * Handle Git repository URL, clone, scan, and clean up.
 */
#[Route('/scan/clone', name: 'scan_clone', methods: ['POST'])]
public function clone(Request $request, GitRepositoryHandler $gitHandler): Response
{
    $repositoryUrl = trim($request->request->getString('repository_url'));

    try {
        $gitHandler->validate($repositoryUrl);
    } catch (InvalidArgumentException $exception) {
        $this->addFlash('danger', $exception->getMessage());

        return $this->redirectToRoute('scan_index');
    }

    try {
        $clonedPath = $gitHandler->clone($repositoryUrl);
    } catch (RuntimeException $exception) {
        $this->addFlash('danger', $exception->getMessage());

        return $this->redirectToRoute('scan_index');
    }

    try {
        $result = $this->scanner->scan($clonedPath);
    } finally {
        $gitHandler->cleanup($clonedPath);
    }

    return $this->render('scan/result.html.twig', [
        'result' => $result,
    ]);
}
```

Add missing imports: `use App\Scanner\GitRepositoryHandler;`, `use RuntimeException;`, `use function trim;`.

**Step 2: Run CI**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 3: Commit**

```bash
git add src/Controller/ScanController.php
git commit -m "Add clone route for scanning Git repositories by URL"
```

---

### Task 4: Add Git-Repository tab to scan template

**Files:**
- Modify: `templates/scan/index.html.twig`

**Step 1: Add the third tab header**

After the ZIP-Upload `<li>` (line ~41), add:

```html
<li class="nav-item" role="presentation">
    <button class="nav-link" id="git-tab" data-bs-toggle="tab"
            data-bs-target="#git-pane" type="button" role="tab"
            aria-controls="git-pane" aria-selected="false">
        <i class="bi bi-git me-1"></i>Git-Repository
    </button>
</li>
```

**Step 2: Add the third tab pane**

After the upload-pane `</div>` (line ~73), add:

```html
<div class="tab-pane fade" id="git-pane" role="tabpanel" aria-labelledby="git-tab">
    <form method="post" action="{{ path('scan_clone') }}">
        <div class="mb-3">
            <label for="repository_url" class="form-label">Repository-URL</label>
            <input type="url" class="form-control" id="repository_url" name="repository_url"
                   placeholder="https://github.com/vendor/my-extension"
                   required>
            <div class="form-text">HTTPS-URL eines öffentlichen GitHub- oder GitLab-Repositories. Das Repository wird temporär geklont und nach dem Scan automatisch gelöscht.</div>
        </div>
        <button type="submit" class="btn btn-typo3">
            <i class="bi bi-cloud-download me-1"></i>Klonen und scannen
        </button>
    </form>
</div>
```

**Step 3: Run CI**

Run: `docker compose exec phpfpm composer ci:test`
Expected: PASS

**Step 4: Commit**

```bash
git add templates/scan/index.html.twig
git commit -m "Add Git-Repository tab to scan page"
```

---

### Task 5: Integration test with real repository (manual)

**Step 1: Start server and test manually**

1. Open the scan page
2. Select "Git-Repository" tab
3. Enter `https://github.com/magicsunday/typo3-migration-analyzer` (or a small public TYPO3 extension)
4. Click "Klonen und scannen"
5. Verify scan results are displayed
6. Verify `/var/tmp/` is cleaned up after scan

**Step 2: Test error cases**

1. Submit empty URL → should show validation error
2. Submit `https://bitbucket.org/vendor/repo` → should show "Nur öffentliche GitHub- und GitLab-Repositories"
3. Submit `https://github.com/nonexistent/nonexistent-repo-12345` → should show clone error

---

### Task 6: Mark feature complete in roadmap

**Files:**
- Modify: `CLAUDE.md` (line 98)

**Step 1: Mark Git-URL-Scan as complete**

Change: `- [ ] Git-URL-Scan (GitRepositoryProvider, \`git clone --depth 1\`, Cleanup)`
To: `- [x] Git-URL-Scan (GitRepositoryHandler, \`git clone --depth 1\`, GitHub/GitLab, Cleanup)`

**Step 2: Add plan to Design + Plan section**

Add: `- \`docs/plans/2026-03-08-git-url-scan.md\` — Git-URL-Scan Plan`

**Step 3: Commit**

```bash
git add CLAUDE.md docs/plans/2026-03-08-git-url-scan.md
git commit -m "Mark Git-URL-Scan as complete in roadmap, add plan"
```
