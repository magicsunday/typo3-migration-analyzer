<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DocumentType;
use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(DocumentService $documentService): Response
    {
        $documents = $documentService->getDocuments();
        $matchers = $documentService->getMatchers();
        $coverage = $documentService->getCoverage();

        $deprecations = array_filter($documents, static fn ($d) => DocumentType::Deprecation === $d->type);
        $breaking = array_filter($documents, static fn ($d) => DocumentType::Breaking === $d->type);

        return $this->render('dashboard/index.html.twig', [
            'totalDocuments' => \count($documents),
            'totalDeprecations' => \count($deprecations),
            'totalBreaking' => \count($breaking),
            'totalMatchers' => \count($matchers),
            'coverage' => $coverage,
        ]);
    }
}
