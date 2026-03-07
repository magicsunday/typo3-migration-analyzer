<?php

declare(strict_types=1);

namespace App\Controller;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\DocumentType;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    #[Route('/', name: 'dashboard')]
    public function index(
        RstFileLocator $locator,
        MatcherConfigParser $matcherParser,
        MatcherCoverageAnalyzer $coverageAnalyzer,
    ): Response {
        $documents = $locator->findAll(self::VERSIONS);
        $matchers = $matcherParser->parseFromInstalledPackage();
        $coverage = $coverageAnalyzer->analyze($documents, $matchers);

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
