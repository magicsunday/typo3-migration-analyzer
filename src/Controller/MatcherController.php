<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MatcherEntry;
use App\Dto\RstDocument;
use App\Generator\MatcherConfigGenerator;
use App\Service\DocumentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function pathinfo;
use function sprintf;

use const PATHINFO_FILENAME;

final class MatcherController extends AbstractController
{
    private const FILENAME_REQUIREMENT = ['filename' => '[A-Za-z0-9_\-]+\.rst'];

    public function __construct(
        private readonly DocumentService $documentService,
        private readonly MatcherConfigGenerator $generator,
    ) {
    }

    #[Route('/matcher-analysis', name: 'matcher_analysis')]
    public function analysis(): Response
    {
        return $this->render('matcher/analysis.html.twig', [
            'coverage' => $this->documentService->getCoverage(),
        ]);
    }

    #[Route('/matcher-analysis/generate/{filename}', name: 'matcher_generate', requirements: self::FILENAME_REQUIREMENT)]
    public function generate(string $filename): Response
    {
        [$doc, $entries, $phpCode] = $this->resolveGeneratedMatcher($filename);

        return $this->render('matcher/generate.html.twig', [
            'doc'     => $doc,
            'entries' => $entries,
            'phpCode' => $phpCode,
        ]);
    }

    #[Route('/matcher-analysis/export/{filename}', name: 'matcher_export', requirements: self::FILENAME_REQUIREMENT)]
    public function export(string $filename): Response
    {
        [, , $phpCode] = $this->resolveGeneratedMatcher($filename);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            pathinfo($filename, PATHINFO_FILENAME) . '.php',
        );

        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * Resolve document, generate matcher entries and render as PHP.
     *
     * @return array{RstDocument, list<MatcherEntry>, string}
     */
    private function resolveGeneratedMatcher(string $filename): array
    {
        $doc = $this->documentService->findDocumentByFilename($filename);

        if ($doc === null) {
            throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
        }

        $entries = $this->generator->generate($doc);
        $phpCode = $this->generator->renderPhp($entries);

        return [$doc, $entries, $phpCode];
    }
}
