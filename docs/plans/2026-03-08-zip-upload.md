# ZIP-Upload Extension Scanner Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Allow users to upload a ZIP file of their TYPO3 extension for scanning, as an alternative to the existing server-path input.

**Architecture:** Add a `ZipUploadHandler` service that validates, extracts, and cleans up uploaded ZIP files in `var/tmp/`. Extend the existing `ScanController` with a new `POST /scan/upload` route. Modify the scan form template to add a tabbed UI (path input vs. ZIP upload). No SourceProvider interface — YAGNI, just a service that returns the extracted path. PHP ini config for 50MB upload limit.

**Tech Stack:** PHP 8.4, Symfony 7.2, Twig, Bootstrap 5.3, ZipArchive

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- English PHPDoc + English inline comments
- `final readonly class` for value objects, one class per file

---

### Task 1: PHP ini config for upload limits

**Files:**
- Create: `rootfs/usr/local/etc/php/conf.d/uploads.ini`

**Step 1: Create the PHP ini file**

```ini
upload_max_filesize = 50M
post_max_size = 55M
```

**Step 2: Rebuild and verify**

Run: `docker compose build phpfpm`
Run: `docker compose up -d`
Run: `docker compose exec -T phpfpm php -i | grep -E "upload_max|post_max"`
Expected: `upload_max_filesize => 50M` and `post_max_size => 55M`

**Step 3: Commit**

```
Configure PHP upload limits for 50MB ZIP uploads
```

---

### Task 2: ZipUploadHandler service

**Files:**
- Create: `src/Scanner/ZipUploadHandler.php`
- Create: `tests/Unit/Scanner/ZipUploadHandlerTest.php`

**Step 1: Write the tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Scanner\ZipUploadHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function file_put_contents;
use function is_dir;
use function mkdir;
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
        // Clean up any leftover temp dirs
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

        $this->expectException(\InvalidArgumentException::class);
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

        // Use a handler with 1 byte max size to force rejection
        $handler = new ZipUploadHandler($this->tmpDir, 1);

        $this->expectException(\InvalidArgumentException::class);
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
    public function cleanupRefusesPathOutsideTmpDir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the temporary directory');

        $this->handler->cleanup('/etc/passwd');
    }

    /**
     * Create a minimal valid ZIP file for testing.
     */
    private function createTestZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'test-zip-') . '.zip';
        $zip     = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('Classes/MyClass.php', '<?php class MyClass {}');
        $zip->addFromString('ext_emconf.php', '<?php $EM_CONF = [];');
        $zip->close();

        return $zipPath;
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

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

**Step 2: Run tests to verify they fail**

Run: `docker compose exec -T phpfpm vendor/bin/phpunit tests/Unit/Scanner/ZipUploadHandlerTest.php`
Expected: FAIL — class `ZipUploadHandler` does not exist.

**Step 3: Implement ZipUploadHandler**

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
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

use function is_dir;
use function mb_strtolower;
use function mkdir;
use function rmdir;
use function sprintf;
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

**Step 4: Register the service with parameter binding**

Modify: `config/services.yaml` — add parameter binding for `$tmpDir`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        bind:
            string $tmpDir: '%kernel.project_dir%/var/tmp'

    App\:
        resource: '../src/'
        exclude:
            - '../src/DependencyInjection/'
            - '../src/Dto/'
            - '../src/Kernel.php'
```

**Step 5: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 6: Commit**

```
Add ZipUploadHandler for validated ZIP extraction with cleanup
```

---

### Task 3: Add upload route to ScanController

**Files:**
- Modify: `src/Controller/ScanController.php`

**Step 1: Add the upload action**

Add this method to `ScanController` after the `run()` method:

```php
#[Route('/scan/upload', name: 'scan_upload', methods: ['POST'])]
public function upload(Request $request, ZipUploadHandler $uploadHandler): Response
{
    /** @var UploadedFile|null $file */
    $file = $request->files->get('extension_zip');

    if (!$file instanceof UploadedFile || !$file->isValid()) {
        $this->addFlash('danger', 'Bitte eine gültige ZIP-Datei auswählen.');

        return $this->redirectToRoute('scan_index');
    }

    try {
        $extractedPath = $uploadHandler->extract($file);
    } catch (InvalidArgumentException $exception) {
        $this->addFlash('danger', $exception->getMessage());

        return $this->redirectToRoute('scan_index');
    }

    try {
        $result = $this->scanner->scan($extractedPath);
    } finally {
        $uploadHandler->cleanup($extractedPath);
    }

    return $this->render('scan/result.html.twig', [
        'result' => $result,
    ]);
}
```

Add the necessary imports:

```php
use App\Scanner\ZipUploadHandler;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
```

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 3: Commit**

```
Add ZIP upload route to ScanController with automatic cleanup
```

---

### Task 4: Update scan form template with tabbed UI

**Files:**
- Modify: `templates/scan/index.html.twig`

**Step 1: Replace the form with a tabbed interface**

Replace the entire `{% block body %}` content:

```twig
{% block body %}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Extension scannen</h1>
        <p class="text-muted mb-0">Analysieren Sie eine TYPO3-Extension auf Deprecations und Breaking Changes.</p>
    </div>
</div>

{% for message in app.flashes('danger') %}
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ message }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schliessen"></button>
    </div>
{% endfor %}

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="path-tab" data-bs-toggle="tab"
                        data-bs-target="#path-pane" type="button" role="tab"
                        aria-controls="path-pane" aria-selected="true">
                    <i class="bi bi-folder2-open me-1"></i>Server-Pfad
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="upload-tab" data-bs-toggle="tab"
                        data-bs-target="#upload-pane" type="button" role="tab"
                        aria-controls="upload-pane" aria-selected="false">
                    <i class="bi bi-upload me-1"></i>ZIP-Upload
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="path-pane" role="tabpanel" aria-labelledby="path-tab">
                <form method="post" action="{{ path('scan_run') }}">
                    <div class="mb-3">
                        <label for="extension_path" class="form-label">Pfad zur Extension</label>
                        <input type="text" class="form-control" id="extension_path" name="extension_path"
                               placeholder="/var/www/html/packages/my_extension"
                               required>
                        <div class="form-text">Absoluter Pfad zum Verzeichnis der TYPO3-Extension auf dem Server.</div>
                    </div>
                    <button type="submit" class="btn btn-typo3">
                        <i class="bi bi-play-circle me-1"></i>Scan starten
                    </button>
                </form>
            </div>
            <div class="tab-pane fade" id="upload-pane" role="tabpanel" aria-labelledby="upload-tab">
                <form method="post" action="{{ path('scan_upload') }}" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="extension_zip" class="form-label">ZIP-Datei der Extension</label>
                        <input type="file" class="form-control" id="extension_zip" name="extension_zip"
                               accept=".zip"
                               required>
                        <div class="form-text">ZIP-Datei der TYPO3-Extension (max. 50 MB). Die Datei wird temporär entpackt und nach dem Scan automatisch gelöscht.</div>
                    </div>
                    <button type="submit" class="btn btn-typo3">
                        <i class="bi bi-upload me-1"></i>Hochladen und scannen
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
{% endblock %}
```

**Step 2: Run tests**

Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 3: Commit**

```
Add tabbed scan form with ZIP upload option
```

---

### Task 5: Add .gitignore for var/tmp

**Files:**
- Modify: `.gitignore`

**Step 1: Add var/tmp to .gitignore**

Append to `.gitignore`:

```
/var/tmp/
```

**Step 2: Commit**

```
Ignore temporary upload directory
```

---

### Task 6: Code review and cleanup

**Step 1: Review all changes**

- Verify ZipUploadHandler has no path traversal vulnerabilities (extractTo could contain `../` in filenames)
- Check `cleanup()` cannot delete paths outside `var/tmp/`
- Verify `try/finally` in controller ensures cleanup even on scanner exceptions
- Check ScanResult template still works with uploaded extension path (shows temp dir — acceptable)
- Verify CSRF token is not needed (no Symfony Form used, consistent with existing pattern)
- Check all flash messages are consistent with existing German language
- Verify upload size is enforced both by PHP ini AND by ZipUploadHandler validation

**Step 2: Fix path traversal protection in ZipUploadHandler**

The `ZipArchive::extractTo()` can be exploited via ZIP entries with `../` in filenames. Add protection by validating entries before extraction:

In `extract()` method, replace the simple `$zip->extractTo($extractDir)` with:

```php
// Validate all entries to prevent path traversal attacks
for ($i = 0; $i < $zip->numFiles; $i++) {
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
```

Add `use function str_contains;` import.

Add corresponding test:

```php
#[Test]
public function extractRejectsZipWithPathTraversal(): void
{
    $zipPath = tempnam(sys_get_temp_dir(), 'traversal-zip-') . '.zip';
    $zip     = new \ZipArchive();
    $zip->open($zipPath, \ZipArchive::CREATE);
    $zip->addFromString('../../../etc/evil.php', '<?php evil();');
    $zip->close();

    $file = new UploadedFile($zipPath, 'evil.zip', 'application/zip', null, true);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('invalid path entries');

    try {
        $this->handler->extract($file);
    } finally {
        unlink($zipPath);
    }
}
```

**Step 3: Run tests**

Run: `docker compose exec -T phpfpm composer ci:cgl`
Run: `docker compose exec -T phpfpm composer ci:rector`
Run: `docker compose exec -T phpfpm composer ci:test`
Expected: All pass.

**Step 4: Commit**

```
Add path traversal protection for ZIP extraction
```
