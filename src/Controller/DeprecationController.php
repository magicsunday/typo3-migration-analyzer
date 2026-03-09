<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Analyzer\ComplexityScorer;
use App\Analyzer\MigrationMappingExtractor;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Service\DocumentService;
use App\Service\LlmAnalysisService;
use App\Service\VersionRangeProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_filter;
use function array_flip;
use function array_values;
use function mb_strtolower;
use function sprintf;
use function str_contains;
use function strtolower;

final class DeprecationController extends AbstractController
{
    #[Route('/deprecations', name: 'deprecation_list')]
    public function list(
        Request $request,
        DocumentService $documentService,
        ComplexityScorer $complexityScorer,
        LlmAnalysisService $llmService,
        VersionRangeProvider $versionRangeProvider,
    ): Response {
        $documents = $documentService->getDocuments();

        $filters = [
            'type'        => $request->query->getString('type'),
            'version'     => $request->query->getString('version'),
            'scan'        => $request->query->getString('scan'),
            'q'           => $request->query->getString('q'),
            'complexity'  => $request->query->getString('complexity'),
            'automatable' => $request->query->getString('automatable'),
        ];

        // Compute complexity scores for all documents before any filtering
        $scores = [];

        foreach ($documents as $doc) {
            $scores[$doc->filename] = $complexityScorer->score($doc);
        }

        if ($filters['type'] !== '') {
            $filterType = DocumentType::tryFrom(strtolower($filters['type']));

            if ($filterType !== null) {
                $documents = array_filter(
                    $documents,
                    static fn (RstDocument $doc): bool => $doc->type === $filterType,
                );
            }
        }

        if ($filters['version'] !== '') {
            $filterVersion = $filters['version'];
            $documents     = array_filter(
                $documents,
                static fn (RstDocument $doc): bool => $doc->version === $filterVersion,
            );
        }

        if ($filters['scan'] !== '') {
            $scanStatus = ScanStatus::tryFrom(
                match ($filters['scan']) {
                    'FullyScanned'     => 'fully_scanned',
                    'PartiallyScanned' => 'partially_scanned',
                    'NotScanned'       => 'not_scanned',
                    default            => $filters['scan'],
                },
            );

            if ($scanStatus !== null) {
                $documents = array_filter(
                    $documents,
                    static fn (RstDocument $doc): bool => $doc->scanStatus === $scanStatus,
                );
            }
        }

        if ($filters['q'] !== '') {
            $query     = mb_strtolower($filters['q']);
            $documents = array_filter(
                $documents,
                static fn (RstDocument $doc): bool => str_contains(mb_strtolower($doc->title), $query)
                    || str_contains(mb_strtolower($doc->filename), $query),
            );
        }

        if ($filters['complexity'] !== '') {
            $filterComplexity = (int) $filters['complexity'];
            $documents        = array_filter(
                $documents,
                static fn (RstDocument $doc): bool => $scores[$doc->filename]->score === $filterComplexity,
            );
        }

        if ($filters['automatable'] === '1') {
            $documents = array_filter(
                $documents,
                static fn (RstDocument $doc): bool => $scores[$doc->filename]->automatable,
            );
        }

        $documents = array_values($documents);

        // Build a lookup set of filenames that have been LLM-analyzed
        $analyzedFilenames = array_flip($llmService->getAnalyzedFilenames());

        return $this->render('deprecation/list.html.twig', [
            'documents'         => $documents,
            'versions'          => $documentService->getVersions(),
            'filters'           => $filters,
            'scores'            => $scores,
            'analyzedFilenames' => $analyzedFilenames,
            'versionRange'      => $documentService->getVersionRange(),
            'majorVersions'     => $versionRangeProvider->getAvailableMajorVersions(),
        ]);
    }

    #[Route('/deprecations/{filename}', name: 'deprecation_detail', requirements: ['filename' => '[A-Za-z0-9_.\-]+\.rst'])]
    public function detail(
        string $filename,
        DocumentService $documentService,
        MigrationMappingExtractor $extractor,
        ComplexityScorer $complexityScorer,
        LlmAnalysisService $llmService,
        VersionRangeProvider $versionRangeProvider,
    ): Response {
        $doc = $documentService->findDocumentByFilename($filename);

        if (!$doc instanceof RstDocument) {
            throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
        }

        return $this->render('deprecation/detail.html.twig', [
            'doc'           => $doc,
            'mappings'      => $extractor->extract($doc->migration, $doc->description),
            'complexity'    => $complexityScorer->score($doc),
            'llmResult'     => $llmService->getLatestResult($filename),
            'versionRange'  => $documentService->getVersionRange(),
            'majorVersions' => $versionRangeProvider->getAvailableMajorVersions(),
        ]);
    }
}
