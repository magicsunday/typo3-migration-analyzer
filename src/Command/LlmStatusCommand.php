<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Command;

use App\Service\DocumentService;
use App\Service\LlmAnalysisService;
use App\Service\LlmConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_find;
use function count;
use function number_format;
use function sprintf;

/**
 * Show the current LLM analysis status and configuration.
 */
#[AsCommand(
    name: 'llm:status',
    description: 'Show LLM analysis status and configuration',
)]
final class LlmStatusCommand extends Command
{
    public function __construct(
        private readonly LlmAnalysisService $analysisService,
        private readonly LlmConfigurationService $configService,
        private readonly DocumentService $documentService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->configService->isConfigured()) {
            $io->warning('LLM is not configured. No API key set.');

            return Command::SUCCESS;
        }

        $config    = $this->configService->load();
        $documents = $this->documentService->getDocuments();
        $progress  = $this->analysisService->getProgress(count($documents));

        $model = array_find(
            $this->configService->getAvailableModels(),
            static fn ($m): bool => $m->modelId === $config->modelId,
        );

        $io->title('LLM Analysis Status');

        $io->definitionList(
            ['Provider' => $config->provider->value],
            ['Model'          => $model !== null ? $model->label : $config->modelId],
            ['Prompt version' => $config->promptVersion],
            ['Analyzed' => sprintf(
                '%d / %d documents (%s%%)',
                $progress['analyzed'],
                $progress['total'],
                number_format($progress['percent'], 1),
            )],
        );

        if ($model !== null) {
            $estimatedCost = $model->estimateCost(
                $progress['analyzed'] * 1500,
                $progress['analyzed'] * 500,
            );

            if ($estimatedCost !== null) {
                $io->note(sprintf('Estimated cost so far: $%s', number_format($estimatedCost, 2)));
            } else {
                $io->note('Cost estimation not available for this model.');
            }
        }

        return Command::SUCCESS;
    }
}
