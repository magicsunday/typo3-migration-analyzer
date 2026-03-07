<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\DocumentType;
use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_filter;
use function count;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'dashboard')]
    public function index(DocumentService $documentService): Response
    {
        $documents = $documentService->getDocuments();
        $coverage  = $documentService->getCoverage();

        $deprecations = array_filter($documents, static fn ($d) => $d->type === DocumentType::Deprecation);
        $breaking     = array_filter($documents, static fn ($d) => $d->type === DocumentType::Breaking);

        return $this->render('dashboard/index.html.twig', [
            'totalDocuments'    => count($documents),
            'totalDeprecations' => count($deprecations),
            'totalBreaking'     => count($breaking),
            'totalMatchers'     => $coverage->totalMatchers,
            'coverage'          => $coverage,
        ]);
    }
}
