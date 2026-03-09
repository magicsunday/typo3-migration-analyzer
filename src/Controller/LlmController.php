<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\LlmAnalysisResult;
use App\Dto\LlmConfiguration;
use App\Dto\LlmProvider;
use App\Dto\RstDocument;
use App\Service\DocumentService;
use App\Service\LlmAnalysisService;
use App\Service\LlmConfigurationService;
use App\Service\VersionRangeProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function count;

/**
 * Handles LLM configuration, single analysis, and bulk analysis.
 */
final class LlmController extends AbstractController
{
    #[Route('/llm/config', name: 'llm_config')]
    public function config(
        Request $request,
        LlmConfigurationService $configService,
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
        VersionRangeProvider $versionRangeProvider,
    ): Response {
        if ($request->isMethod('POST')) {
            $provider = LlmProvider::tryFrom($request->request->getString('provider')) ?? LlmProvider::Claude;
            $prompt   = $request->request->getString('analysis_prompt');

            if ($prompt === '') {
                $prompt = $configService->getDefaultPrompt();
            }

            $config = new LlmConfiguration(
                provider: $provider,
                modelId: $request->request->getString('model_id'),
                apiKey: $request->request->getString('api_key'),
                analysisPrompt: $prompt,
                promptVersion: $configService->getPromptVersion($prompt),
            );

            $configService->save($config);

            $this->addFlash('success', 'LLM-Konfiguration gespeichert.');

            return $this->redirectToRoute('llm_config');
        }

        $config    = $configService->load();
        $documents = $documentService->getDocuments();
        $progress  = $analysisService->getProgress(count($documents));

        return $this->render('llm/config.html.twig', [
            'config'        => $config,
            'models'        => $configService->getAvailableModels($config->provider, $config->apiKey),
            'defaultPrompt' => $configService->getDefaultPrompt(),
            'progress'      => $progress,
            'versionRange'  => $documentService->getVersionRange(),
            'majorVersions' => $versionRangeProvider->getAvailableMajorVersions(),
        ]);
    }

    #[Route('/llm/analyze/{filename}', name: 'llm_analyze_single', methods: ['POST'])]
    public function analyzeSingle(
        string $filename,
        Request $request,
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
    ): JsonResponse {
        $document = $documentService->findDocumentByFilename($filename);

        if (!$document instanceof RstDocument) {
            return $this->json(['error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $force  = $request->request->getBoolean('force');
            $result = $analysisService->analyze($document, $force);

            if (!$result instanceof LlmAnalysisResult) {
                return $this->json(['error' => 'LLM not configured'], Response::HTTP_BAD_REQUEST);
            }

            return $this->json([
                'score'           => $result->score,
                'automationGrade' => $result->automationGrade->value,
                'summary'         => $result->summary,
                'migrationSteps'  => $result->migrationSteps,
                'affectedAreas'   => $result->affectedAreas,
                'tokensInput'     => $result->tokensInput,
                'tokensOutput'    => $result->tokensOutput,
                'durationMs'      => $result->durationMs,
                'modelId'         => $result->modelId,
                'createdAt'       => $result->createdAt,
            ]);
        } catch (Throwable $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/llm/analyze-bulk', name: 'llm_analyze_bulk', methods: ['POST'])]
    public function analyzeBulk(
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
    ): JsonResponse {
        $documents = $documentService->getDocuments();

        // Find the first document without a cached result
        foreach ($documents as $document) {
            $cached = $analysisService->getCachedResult($document->filename);

            if ($cached instanceof LlmAnalysisResult) {
                continue;
            }

            try {
                $result   = $analysisService->analyze($document);
                $progress = $analysisService->getProgress(count($documents));

                return $this->json([
                    'filename' => $document->filename,
                    'result'   => $result instanceof LlmAnalysisResult ? [
                        'score'   => $result->score,
                        'summary' => $result->summary,
                    ] : null,
                    'progress' => $progress,
                ]);
            } catch (Throwable $e) {
                $progress = $analysisService->getProgress(count($documents));

                return $this->json([
                    'filename' => $document->filename,
                    'error'    => $e->getMessage(),
                    'progress' => $progress,
                ]);
            }
        }

        // All documents analyzed
        $progress = $analysisService->getProgress(count($documents));

        return $this->json([
            'filename' => null,
            'complete' => true,
            'progress' => $progress,
        ]);
    }

    #[Route('/llm/progress', name: 'llm_progress', methods: ['GET'])]
    public function progress(
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
    ): JsonResponse {
        $documents = $documentService->getDocuments();

        return $this->json($analysisService->getProgress(count($documents)));
    }
}
