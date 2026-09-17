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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function file_exists;
use function file_get_contents;
use function preg_replace;
use function rtrim;
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

    #[Test]
    public function executeSucceedsWithFindingsWhenFailOnFindingsIsNotSet(): void
    {
        $statusCode = $this->tester->execute(['source' => self::FIXTURE_PATH]);

        self::assertSame(Command::SUCCESS, $statusCode);
    }

    #[Test]
    public function executeFailsWithFindingsWhenFailOnFindingsIsSet(): void
    {
        $statusCode = $this->tester->execute([
            'source'             => self::FIXTURE_PATH,
            '--fail-on-findings' => true,
        ]);

        self::assertSame(Command::FAILURE, $statusCode);
    }

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
     * Collapse whitespace so a SymfonyStyle block's word-wrapped line breaks
     * do not break a substring match against its rendered message.
     */
    private function normalizeWhitespace(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? $value;
    }
}
