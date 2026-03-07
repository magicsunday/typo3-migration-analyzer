<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Coverage report with detailed breakdown by version, type, scan status and matcher type.
 */
final class CoverageController extends AbstractController
{
    #[Route('/coverage', name: 'coverage_report')]
    public function index(DocumentService $documentService): Response
    {
        return $this->render('coverage/index.html.twig', [
            'coverage' => $documentService->getCoverage(),
        ]);
    }
}
