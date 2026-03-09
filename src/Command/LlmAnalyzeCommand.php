<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Command;

use App\Dto\LlmAnalysisResult;
use App\Dto\RstDocument;
use App\Service\DocumentService;
use App\Service\LlmAnalysisService;
use App\Service\LlmConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function array_map;
use function count;
use function usleep;

/**
 * Analyze RST documents using the configured LLM provider.
 */
#[AsCommand(
    name: 'llm:analyze',
    description: 'Analyze RST documents using the configured LLM',
)]
final class LlmAnalyzeCommand extends Command
{
    public function __construct(
        private readonly LlmAnalysisService $analysisService,
        private readonly LlmConfigurationService $configService,
        private readonly DocumentService $documentService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('filename', InputArgument::OPTIONAL, 'Single RST filename to analyze')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Analyze all documents')
            ->addOption('missing', null, InputOption::VALUE_NONE, 'Only analyze documents without cached results')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force re-analysis even if cached');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->configService->isConfigured()) {
            $io->error('LLM is not configured. Set an API key via the web UI or var/data/llm_config.yaml.');

            return Command::FAILURE;
        }

        $config = $this->configService->load();
        $io->info(sprintf('Using model: %s', $config->modelId));

        /** @var string|null $filename */
        $filename = $input->getArgument('filename');

        if ($filename !== null) {
            return $this->analyzeSingle($io, $filename, $input->getOption('force') === true);
        }

        if ($input->getOption('all') !== true && $input->getOption('missing') !== true) {
            $io->error('Specify a filename, --all, or --missing.');

            return Command::FAILURE;
        }

        return $this->analyzeBulk(
            $io,
            $output,
            $input->getOption('force') === true,
            $input->getOption('missing') === true,
        );
    }

    /**
     * Analyze a single document by filename.
     */
    private function analyzeSingle(SymfonyStyle $io, string $filename, bool $force): int
    {
        $document = $this->documentService->findDocumentByFilename($filename);

        if (!$document instanceof RstDocument) {
            $io->error(sprintf('Document not found: %s', $filename));

            return Command::FAILURE;
        }

        $result = $this->analysisService->analyze($document, $force);

        if (!$result instanceof LlmAnalysisResult) {
            $io->error('Analysis failed.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Score: %d | Grade: %s | %s',
            $result->score,
            $result->automationGrade->value,
            $result->summary,
        ));

        return Command::SUCCESS;
    }

    /**
     * Analyze all or missing documents with progress tracking.
     */
    private function analyzeBulk(SymfonyStyle $io, OutputInterface $output, bool $force, bool $missingOnly): int
    {
        $documents = $this->documentService->getDocuments();
        $filenames = array_map(static fn (RstDocument $doc): string => $doc->filename, $documents);

        if ($missingOnly) {
            $analyzedFilenames = $this->analysisService->getProgress($filenames);
            $io->info(sprintf('Already analyzed: %d/%d', $analyzedFilenames['analyzed'], $analyzedFilenames['total']));
        }

        $progressBar = new ProgressBar($output, count($documents));
        $progressBar->start();

        $analyzed = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($documents as $document) {
            $progressBar->advance();

            // Skip already-analyzed documents in missing-only mode
            if ($missingOnly && !$force) {
                $cached = $this->analysisService->getCachedResult($document->filename);

                if ($cached instanceof LlmAnalysisResult) {
                    ++$skipped;

                    continue;
                }
            }

            try {
                $result = $this->analysisService->analyze($document, $force);

                if ($result instanceof LlmAnalysisResult) {
                    ++$analyzed;
                } else {
                    ++$errors;
                }
            } catch (Throwable $e) {
                ++$errors;
                $io->warning(sprintf('%s: %s', $document->filename, $e->getMessage()));
            }

            // Rate limiting: 1 second between API calls
            usleep(1_000_000);
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf(
            'Analyzed: %d | Skipped: %d | Errors: %d',
            $analyzed,
            $skipped,
            $errors,
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
