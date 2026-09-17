<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Command;

use App\Dto\ScanResult;
use App\Scanner\ExtensionScanner;
use App\Scanner\GitRepositoryHandlerInterface;
use App\Scanner\ScanReportExporter;
use App\Scanner\ScanSourcePathResolver;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function dirname;
use function file_put_contents;
use function implode;
use function in_array;
use function is_dir;
use function sprintf;
use function strlen;

/**
 * Scan a TYPO3 extension for deprecated API usage from the command line.
 *
 * Accepts either a local directory path or a public GitHub/GitLab repository
 * URL as source, so it can be wired into CI/CD pipelines without going
 * through the web UI.
 */
#[AsCommand(
    name: 'scan:extension',
    description: 'Scan a TYPO3 extension for deprecated API usage',
)]
final class ScanExtensionCommand extends Command
{
    /**
     * @var list<string>
     */
    private const array VALID_FORMATS = ['text', 'json', 'csv', 'markdown'];

    public function __construct(
        private readonly ExtensionScanner $scanner,
        private readonly GitRepositoryHandlerInterface $gitHandler,
        private readonly ScanReportExporter $exporter,
        private readonly ScanSourcePathResolver $scanSourcePathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Local directory path (rewritten via SCAN_SOURCE_PATH like the web scan form, '
                . 'see ScanSourcePathResolver) or public GitHub/GitLab repository URL to scan',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Report format (%s)',
                    implode('|', self::VALID_FORMATS),
                ),
                'text',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write the report to a file instead of stdout',
            )
            ->addOption(
                'fail-on-findings',
                null,
                InputOption::VALUE_NONE,
                'Exit with a non-zero status if the scan produced findings',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $source */
        $source = $input->getArgument('source');
        $source = $this->scanSourcePathResolver->resolve($source);
        /** @var string $format */
        $format = $input->getOption('format');

        if (!in_array($format, self::VALID_FORMATS, true)) {
            return $this->fail($io, sprintf(
                'Invalid format "%s". Allowed: %s',
                $format,
                implode(', ', self::VALID_FORMATS),
            ));
        }

        if (is_dir($source)) {
            return $this->scanAndReport($input, $io, $source, $format);
        }

        try {
            $clonedPath = $this->gitHandler->clone($source);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->fail($io, $exception->getMessage());
        }

        try {
            return $this->scanAndReport($input, $io, $clonedPath, $format);
        } finally {
            $this->gitHandler->cleanup($clonedPath);
        }
    }

    /**
     * Run the scan against a resolved local path and render the report.
     */
    private function scanAndReport(InputInterface $input, SymfonyStyle $io, string $path, string $format): int
    {
        try {
            $result = $this->scanner->scan($path);
        } catch (Throwable $exception) {
            return $this->fail($io, $exception->getMessage());
        }

        $report = $this->renderReport($result, $format);

        /** @var string|null $outputFile */
        $outputFile = $input->getOption('output');

        if ($outputFile !== null) {
            $bytesWritten = is_dir(dirname($outputFile)) ? file_put_contents($outputFile, $report) : false;

            if (
                ($bytesWritten === false)
                || ($bytesWritten !== strlen($report))
            ) {
                return $this->fail($io, sprintf(
                    'Failed to write report to %s',
                    $outputFile,
                ));
            }

            $io->success(sprintf(
                'Report written to %s',
                $outputFile,
            ));
        } else {
            $io->writeln($report);
        }

        if (
            ($input->getOption('fail-on-findings') === true)
            && ($result->totalFindings() > 0)
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Render the scan result in the requested format. $format was already
     * validated in execute(), so an unrecognized value cannot reach the
     * default case, which is reserved for the "text" format.
     */
    private function renderReport(ScanResult $result, string $format): string
    {
        return match ($format) {
            'json'     => $this->exporter->toJson($result),
            'csv'      => $this->exporter->toCsv($result),
            'markdown' => $this->exporter->toMarkdown($result),
            default    => $this->exporter->toText($result),
        };
    }

    /**
     * Report an error message and signal command failure.
     */
    private function fail(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return Command::FAILURE;
    }
}
