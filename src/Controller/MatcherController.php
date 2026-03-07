<?php

declare(strict_types=1);

namespace App\Controller;

use App\Generator\MatcherConfigGenerator;
use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MatcherController extends AbstractController
{
    #[Route('/matcher-analysis', name: 'matcher_analysis')]
    public function analysis(DocumentService $documentService): Response
    {
        $coverage = $documentService->getCoverage();

        return $this->render('matcher/analysis.html.twig', [
            'coverage' => $coverage,
        ]);
    }

    #[Route('/matcher-analysis/generate/{filename}', name: 'matcher_generate')]
    public function generate(string $filename, DocumentService $documentService, MatcherConfigGenerator $generator): Response
    {
        $doc = $documentService->findDocumentByFilename($filename);

        if (null === $doc) {
            throw $this->createNotFoundException(\sprintf('Document "%s" not found.', $filename));
        }

        $entries = $generator->generate($doc);
        $phpCode = $generator->renderPhp($entries);

        return $this->render('matcher/generate.html.twig', [
            'doc' => $doc,
            'entries' => $entries,
            'phpCode' => $phpCode,
        ]);
    }

    #[Route('/matcher-analysis/export/{filename}', name: 'matcher_export')]
    public function export(string $filename, DocumentService $documentService, MatcherConfigGenerator $generator): Response
    {
        $doc = $documentService->findDocumentByFilename($filename);

        if (null === $doc) {
            throw $this->createNotFoundException(\sprintf('Document "%s" not found.', $filename));
        }

        $entries = $generator->generate($doc);
        $phpCode = $generator->renderPhp($entries);

        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'application/x-php');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s.php"', pathinfo($filename, \PATHINFO_FILENAME)));

        return $response;
    }
}
