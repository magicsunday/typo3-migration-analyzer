<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanner;

use App\Dto\ScanResult;
use App\Scanner\ExtensionScanner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtensionScannerTest extends TestCase
{
    private ExtensionScanner $scanner;

    private string $fixturePath;

    protected function setUp(): void
    {
        $this->scanner     = new ExtensionScanner();
        $this->fixturePath = __DIR__ . '/../../Fixtures/Extension';
    }

    #[Test]
    public function scanReturnsResultForValidPath(): void
    {
        $result = $this->scanner->scan($this->fixturePath);

        self::assertInstanceOf(ScanResult::class, $result);
        self::assertGreaterThanOrEqual(2, $result->scannedFiles());
    }

    #[Test]
    public function scanFindsDeprecatedApiUsage(): void
    {
        $result = $this->scanner->scan($this->fixturePath);

        self::assertNotEmpty($result->filesWithFindings());
        self::assertGreaterThanOrEqual(1, $result->totalFindings());
    }

    #[Test]
    public function scanFindingContainsLineAndMessage(): void
    {
        $result   = $this->scanner->scan($this->fixturePath);
        $findings = $result->filesWithFindings();

        self::assertNotEmpty($findings);

        $firstFinding = $findings[0]->findings[0];

        self::assertGreaterThan(0, $firstFinding->line);
        self::assertNotEmpty($firstFinding->message);
        self::assertNotEmpty($firstFinding->restFiles);
    }

    #[Test]
    public function scanThrowsForInvalidPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->scanner->scan('/nonexistent/path/to/extension');
    }

    #[Test]
    public function scanSkipsNonPhpFiles(): void
    {
        $result = $this->scanner->scan($this->fixturePath);

        foreach ($result->fileResults as $fileResult) {
            self::assertStringEndsWith('.php', $fileResult->filePath);
        }
    }
}
