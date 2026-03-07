<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Integration\Scanner;

use App\Scanner\ExtensionScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Integration test that runs the full scanner pipeline against the test fixture extension.
 */
final class ExtensionScannerIntegrationTest extends TestCase
{
    #[Test]
    public function scanFixtureExtensionProducesFindings(): void
    {
        $scanner     = new ExtensionScanner();
        $fixturePath = dirname(__DIR__, 2) . '/Fixtures/Extension';

        $result = $scanner->scan($fixturePath);

        self::assertGreaterThanOrEqual(1, $result->scannedFiles());
        self::assertGreaterThanOrEqual(1, $result->totalFindings());

        // Verify finding structure
        $filesWithFindings = $result->filesWithFindings();
        self::assertNotEmpty($filesWithFindings);

        $finding = $filesWithFindings[0]->findings[0];
        self::assertGreaterThan(0, $finding->line);
        self::assertNotEmpty($finding->message);
        self::assertNotEmpty($finding->restFiles);
        self::assertNotEmpty($finding->lineContent);
    }

    #[Test]
    public function scanCleanDirectoryProducesNoFindings(): void
    {
        $scanner = new ExtensionScanner();

        $tmpDir = sys_get_temp_dir() . '/ext_scanner_test_' . uniqid();
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/Clean.php', "<?php\n\nclass Clean {}\n");

        try {
            $result = $scanner->scan($tmpDir);
            self::assertSame(0, $result->totalFindings());
        } finally {
            unlink($tmpDir . '/Clean.php');
            rmdir($tmpDir);
        }
    }
}
