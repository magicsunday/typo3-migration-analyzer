<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ScanExtensionCommand;
use App\Scanner\ExtensionScanner;
use App\Scanner\GitRepositoryHandler;
use App\Scanner\ScanReportExporter;
use App\Scanner\ScanSourcePathResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function chmod;
use function dirname;
use function file_exists;
use function file_get_contents;
use function mkdir;
use function preg_replace;
use function restore_error_handler;
use function rmdir;
use function rtrim;
use function set_error_handler;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(ScanExtensionCommand::class)]
final class ScanExtensionCommandTest extends TestCase
{
    private const string FIXTURE_PATH = __DIR__ . '/../../Fixtures/Extension';

    private const string CLEAN_FIXTURE_PATH = __DIR__ . '/../../Fixtures/CleanExtension';

    private CommandTester $tester;

    protected function setUp(): void
    {
        $command = new ScanExtensionCommand(
            new ExtensionScanner(),
            new GitRepositoryHandler(sys_get_temp_dir() . '/scan-extension-command-test-' . uniqid()),
            new ScanReportExporter(),
            new ScanSourcePathResolver('', ''),
        );

        $this->tester = new CommandTester($command);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formatProvider(): array
    {
        return [
            'text'     => ['text'],
            'json'     => ['json'],
            'csv'      => ['csv'],
            'markdown' => ['markdown'],
        ];
    }

    /**
     * Each supported --format value must render the same output the command
     * routes it through the corresponding ScanReportExporter method.
     */
    #[Test]
    #[DataProvider('formatProvider')]
    public function executeRoutesEachFormatThroughItsExporterMethod(string $format): void
    {
        $result   = (new ExtensionScanner())->scan(self::FIXTURE_PATH);
        $exporter = new ScanReportExporter();
        $expected = match ($format) {
            'text'     => $exporter->toText($result),
            'json'     => $exporter->toJson($result),
            'csv'      => $exporter->toCsv($result),
            'markdown' => $exporter->toMarkdown($result),
            default    => self::fail(sprintf('Unexpected format "%s" from data provider.', $format)),
        };

        $statusCode = $this->tester->execute([
            'source'   => self::FIXTURE_PATH,
            '--format' => $format,
        ]);

        self::assertSame(Command::SUCCESS, $statusCode);
        self::assertSame(rtrim($expected), rtrim($this->tester->getDisplay()));
    }

    /**
     * A --format value outside the supported list must fail fast without scanning.
     */
    #[Test]
    public function executeFailsForUnknownFormat(): void
    {
        $statusCode = $this->tester->execute([
            'source'   => self::FIXTURE_PATH,
            '--format' => 'yaml',
        ]);

        self::assertSame(Command::FAILURE, $statusCode);
        self::assertStringContainsString('Invalid format', $this->tester->getDisplay());
    }

    /**
     * A source that is neither a local directory nor a valid GitHub/GitLab
     * URL must be rejected with GitRepositoryHandler's validation message,
     * reached through clone()'s own internal validate() call.
     */
    #[Test]
    public function executeFailsForPathThatIsNeitherADirectoryNorAValidRepositoryUrl(): void
    {
        $statusCode = $this->tester->execute(['source' => '/does/not/exist']);

        self::assertSame(Command::FAILURE, $statusCode);
        self::assertStringContainsString(
            'Nur öffentliche GitHub- und GitLab-Repositories werden unterstützt.',
            $this->tester->getDisplay(),
        );
    }

    /**
     * With --output set, the report goes to that file (not stdout) and a
     * success message naming the file is printed.
     */
    #[Test]
    public function executeWritesReportToOutputFileInsteadOfStdout(): void
    {
        $outputFile = sys_get_temp_dir() . '/scan-extension-command-output-' . uniqid() . '.json';

        try {
            $statusCode = $this->tester->execute([
                'source'   => self::FIXTURE_PATH,
                '--format' => 'json',
                '--output' => $outputFile,
            ]);

            $expected = (new ScanReportExporter())->toJson((new ExtensionScanner())->scan(self::FIXTURE_PATH));

            self::assertSame(Command::SUCCESS, $statusCode);
            self::assertFileExists($outputFile);
            self::assertSame($expected, file_get_contents($outputFile));
            self::assertStringNotContainsString('"extensionPath"', $this->tester->getDisplay());
            self::assertStringContainsString(
                $this->normalizeWhitespace(sprintf('Report written to %s', $outputFile)),
                $this->normalizeWhitespace($this->tester->getDisplay()),
            );
        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    /**
     * A --output path whose parent directory does not exist must fail
     * cleanly without ever attempting the write.
     */
    #[Test]
    public function executeFailsWhenOutputFileCannotBeWritten(): void
    {
        $outputFile = sys_get_temp_dir() . '/scan-extension-command-test-' . uniqid() . '/does-not-exist/report.json';

        $statusCode = $this->tester->execute([
            'source'   => self::FIXTURE_PATH,
            '--format' => 'json',
            '--output' => $outputFile,
        ]);

        self::assertSame(Command::FAILURE, $statusCode);
        self::assertStringContainsString(
            $this->normalizeWhitespace(sprintf('Failed to write report to %s', $outputFile)),
            $this->normalizeWhitespace($this->tester->getDisplay()),
        );
        self::assertFileDoesNotExist($outputFile);
    }

    /**
     * A --output path whose parent directory exists but is read-only must
     * still fail cleanly, exercising the file_put_contents()-return-value
     * half of the write guard (as opposed to the is_dir() half above).
     */
    #[Test]
    public function executeFailsWhenOutputDirectoryExistsButIsNotWritable(): void
    {
        $outputDirectory = sys_get_temp_dir() . '/scan-extension-command-test-readonly-' . uniqid();
        mkdir($outputDirectory, 0o755, true);
        chmod($outputDirectory, 0o555);
        $outputFile = $outputDirectory . '/report.json';

        // file_put_contents() itself raises an E_WARNING on a permission-denied
        // write; the command already turns that into a clean Command::FAILURE
        // via its return-value check, so the raw PHP warning is expected here
        // and suppressed for the duration of the call under test.
        set_error_handler(static fn (): bool => true);

        try {
            $statusCode = $this->tester->execute([
                'source'   => self::FIXTURE_PATH,
                '--format' => 'json',
                '--output' => $outputFile,
            ]);
        } finally {
            restore_error_handler();
        }

        try {
            self::assertSame(Command::FAILURE, $statusCode);
            self::assertStringContainsString(
                $this->normalizeWhitespace(sprintf('Failed to write report to %s', $outputFile)),
                $this->normalizeWhitespace($this->tester->getDisplay()),
            );
            self::assertFileDoesNotExist($outputFile);
        } finally {
            chmod($outputDirectory, 0o755);
            rmdir($outputDirectory);
        }
    }

    /**
     * Without --fail-on-findings, a scan that produces findings still exits successfully.
     */
    #[Test]
    public function executeSucceedsWithFindingsWhenFailOnFindingsIsNotSet(): void
    {
        $statusCode = $this->tester->execute(['source' => self::FIXTURE_PATH]);

        self::assertSame(Command::SUCCESS, $statusCode);
    }

    /**
     * With --fail-on-findings, a scan that produces findings exits with a non-zero status.
     */
    #[Test]
    public function executeFailsWithFindingsWhenFailOnFindingsIsSet(): void
    {
        $statusCode = $this->tester->execute([
            'source'             => self::FIXTURE_PATH,
            '--fail-on-findings' => true,
        ]);

        self::assertSame(Command::FAILURE, $statusCode);
    }

    /**
     * With --fail-on-findings set, a scan that produces zero findings still
     * exits successfully, isolating the flag's own findings-count check.
     */
    #[Test]
    public function executeSucceedsWhenFailOnFindingsIsSetButScanHasNoFindings(): void
    {
        $statusCode = $this->tester->execute([
            'source'             => self::CLEAN_FIXTURE_PATH,
            '--fail-on-findings' => true,
        ]);

        self::assertSame(Command::SUCCESS, $statusCode);
        self::assertMatchesRegularExpression('/Findings: 0\b/', $this->tester->getDisplay());
    }

    /**
     * A host-style source path must be rewritten to its container-visible
     * equivalent via ScanSourcePathResolver before the scan runs.
     */
    #[Test]
    public function executeResolvesSourceThroughTheScanSourcePathResolver(): void
    {
        $command = new ScanExtensionCommand(
            new ExtensionScanner(),
            new GitRepositoryHandler(sys_get_temp_dir() . '/scan-extension-command-test-' . uniqid()),
            new ScanReportExporter(),
            new ScanSourcePathResolver('/synthetic-host-alias', dirname(self::FIXTURE_PATH)),
        );

        $statusCode = (new CommandTester($command))->execute(['source' => '/synthetic-host-alias/Extension']);

        self::assertSame(Command::SUCCESS, $statusCode);
    }

    /**
     * Collapse whitespace so a SymfonyStyle block's word-wrapped line breaks
     * do not break a substring match against its rendered message.
     */
    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? $value;
    }
}
