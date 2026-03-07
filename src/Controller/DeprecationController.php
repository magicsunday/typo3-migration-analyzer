<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DocumentType;
use App\Dto\ScanStatus;
use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeprecationController extends AbstractController
{
    #[Route('/deprecations', name: 'deprecation_list')]
    public function list(Request $request, DocumentService $documentService): Response
    {
        $documents = $documentService->getDocuments();

        $filters = [
            'type' => $request->query->getString('type'),
            'version' => $request->query->getString('version'),
            'scan' => $request->query->getString('scan'),
            'q' => $request->query->getString('q'),
        ];

        if ('' !== $filters['type']) {
            $filterType = DocumentType::tryFrom(strtolower($filters['type']));

            if (null !== $filterType) {
                $documents = array_filter(
                    $documents,
                    static fn ($doc) => $doc->type === $filterType,
                );
            }
        }

        if ('' !== $filters['version']) {
            $filterVersion = $filters['version'];
            $documents = array_filter(
                $documents,
                static fn ($doc) => $doc->version === $filterVersion,
            );
        }

        if ('' !== $filters['scan']) {
            $scanStatus = ScanStatus::tryFrom(
                match ($filters['scan']) {
                    'FullyScanned' => 'fully_scanned',
                    'PartiallyScanned' => 'partially_scanned',
                    'NotScanned' => 'not_scanned',
                    default => $filters['scan'],
                },
            );

            if (null !== $scanStatus) {
                $documents = array_filter(
                    $documents,
                    static fn ($doc) => $doc->scanStatus === $scanStatus,
                );
            }
        }

        if ('' !== $filters['q']) {
            $query = mb_strtolower($filters['q']);
            $documents = array_filter(
                $documents,
                static fn ($doc) => str_contains(mb_strtolower($doc->title), $query)
                    || str_contains(mb_strtolower($doc->filename), $query),
            );
        }

        $documents = array_values($documents);

        return $this->render('deprecation/list.html.twig', [
            'documents' => $documents,
            'versions' => $documentService->getVersions(),
            'filters' => $filters,
        ]);
    }

    #[Route('/deprecations/{filename}', name: 'deprecation_detail')]
    public function detail(string $filename, DocumentService $documentService): Response
    {
        $doc = $documentService->findDocumentByFilename($filename);

        if (null === $doc) {
            throw $this->createNotFoundException(\sprintf('Document "%s" not found.', $filename));
        }

        return $this->render('deprecation/detail.html.twig', [
            'doc' => $doc,
        ]);
    }
}
