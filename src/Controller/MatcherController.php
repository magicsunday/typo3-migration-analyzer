<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Generator\MatcherConfigGenerator;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MatcherController extends AbstractController
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    #[Route('/matcher-analysis', name: 'matcher_analysis')]
    public function analysis(
        RstFileLocator $locator,
        MatcherConfigParser $matcherParser,
        MatcherCoverageAnalyzer $coverageAnalyzer,
    ): Response {
        $documents = $locator->findAll(self::VERSIONS);
        $matchers = $matcherParser->parseFromInstalledPackage();
        $coverage = $coverageAnalyzer->analyze($documents, $matchers);

        return $this->render('matcher/analysis.html.twig', [
            'coverage' => $coverage,
        ]);
    }

    #[Route('/matcher-analysis/generate/{filename}', name: 'matcher_generate')]
    public function generate(string $filename, RstFileLocator $locator, MatcherConfigGenerator $generator): Response
    {
        $doc = $this->findDocumentByFilename($filename, $locator);

        $entries = $generator->generate($doc);
        $phpCode = $generator->renderPhp($entries);

        return $this->render('matcher/generate.html.twig', [
            'doc' => $doc,
            'entries' => $entries,
            'phpCode' => $phpCode,
        ]);
    }

    #[Route('/matcher-analysis/export/{filename}', name: 'matcher_export')]
    public function export(string $filename, RstFileLocator $locator, MatcherConfigGenerator $generator): Response
    {
        $doc = $this->findDocumentByFilename($filename, $locator);

        $entries = $generator->generate($doc);
        $phpCode = $generator->renderPhp($entries);

        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'application/x-php');
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s.php"', pathinfo($filename, \PATHINFO_FILENAME)));

        return $response;
    }

    private function findDocumentByFilename(string $filename, RstFileLocator $locator): \App\Dto\RstDocument
    {
        $documents = $locator->findAll(self::VERSIONS);

        foreach ($documents as $document) {
            if ($document->filename === $filename) {
                return $document;
            }
        }

        throw $this->createNotFoundException(\sprintf('Document "%s" not found.', $filename));
    }
}
