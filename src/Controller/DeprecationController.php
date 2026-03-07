<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DocumentType;
use App\Dto\ScanStatus;
use App\Parser\RstFileLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DeprecationController extends AbstractController
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    #[Route('/deprecations', name: 'deprecation_list')]
    public function list(Request $request, RstFileLocator $locator): Response
    {
        $documents = $locator->findAll(self::VERSIONS);

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
            'versions' => self::VERSIONS,
            'filters' => $filters,
        ]);
    }

    #[Route('/deprecations/{filename}', name: 'deprecation_detail')]
    public function detail(string $filename, RstFileLocator $locator): Response
    {
        $documents = $locator->findAll(self::VERSIONS);

        $doc = null;

        foreach ($documents as $document) {
            if ($document->filename === $filename) {
                $doc = $document;

                break;
            }
        }

        if (null === $doc) {
            throw $this->createNotFoundException(\sprintf('Document "%s" not found.', $filename));
        }

        return $this->render('deprecation/detail.html.twig', [
            'doc' => $doc,
        ]);
    }
}
