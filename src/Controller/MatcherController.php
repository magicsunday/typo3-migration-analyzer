<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\MatcherEntry;
use App\Dto\RectorRule;
use App\Dto\RstDocument;
use App\Generator\MatcherConfigGenerator;
use App\Generator\RectorRuleGenerator;
use App\Service\DocumentService;
use App\Service\VersionRangeProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use ZipArchive;

use function array_filter;
use function array_values;
use function count;
use function pathinfo;
use function readfile;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PATHINFO_FILENAME;

final class MatcherController extends AbstractController
{
    private const array FILENAME_REQUIREMENT = ['filename' => '[A-Za-z0-9_.\-]+\.rst'];

    public function __construct(
        private readonly DocumentService $documentService,
        private readonly MatcherConfigGenerator $generator,
        private readonly RectorRuleGenerator $rectorGenerator,
        private readonly VersionRangeProvider $versionRangeProvider,
    ) {
    }

    #[Route('/matcher-analysis', name: 'matcher_analysis')]
    public function analysis(): Response
    {
        return $this->render('matcher/analysis.html.twig', [
            'coverage'      => $this->documentService->getCoverage(),
            'versionRange'  => $this->documentService->getVersionRange(),
            'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
        ]);
    }

    #[Route('/matcher-analysis/generate/{filename}', name: 'matcher_generate', requirements: self::FILENAME_REQUIREMENT)]
    public function generate(string $filename): Response
    {
        [$doc, $entries, $phpCode] = $this->resolveGeneratedMatcher($filename);

        $rectorRules         = $this->rectorGenerator->generate($doc);
        $rectorConfigRules   = array_values(array_filter($rectorRules, static fn (RectorRule $r): bool => $r->isConfig()));
        $rectorSkeletonRules = array_values(array_filter($rectorRules, static fn (RectorRule $r): bool => !$r->isConfig()));

        return $this->render('matcher/generate.html.twig', [
            'doc'                 => $doc,
            'entries'             => $entries,
            'phpCode'             => $phpCode,
            'rectorConfigRules'   => $rectorConfigRules,
            'rectorConfigPhp'     => $rectorConfigRules !== [] ? $this->rectorGenerator->renderConfig($rectorConfigRules) : null,
            'rectorSkeletonRules' => $rectorSkeletonRules,
            'rectorGenerator'     => $this->rectorGenerator,
            'versionRange'        => $this->documentService->getVersionRange(),
            'majorVersions'       => $this->versionRangeProvider->getAvailableMajorVersions(),
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

    #[Route('/matcher-analysis/export-all', name: 'matcher_export_all')]
    public function exportAll(): StreamedResponse
    {
        $coverage = $this->documentService->getCoverage();

        $response = new StreamedResponse(function () use ($coverage): void {
            $zip     = new ZipArchive();
            $tmpFile = tempnam(sys_get_temp_dir(), 'matcher_export_');

            if ($tmpFile === false || $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Failed to create ZIP archive.');
            }

            foreach ($coverage->uncovered as $doc) {
                $entries = $this->generator->generate($doc);

                if ($entries === []) {
                    continue;
                }

                $phpCode  = $this->generator->renderPhp($entries);
                $filename = pathinfo($doc->filename, PATHINFO_FILENAME) . '.php';

                $zip->addFromString($filename, $phpCode);
            }

            $zip->close();

            readfile($tmpFile);
            unlink($tmpFile);
        });

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'matcher-configs.zip',
        );

        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/matcher-analysis/export-rector-config/{filename}', name: 'rector_export_config', requirements: self::FILENAME_REQUIREMENT)]
    public function exportRectorConfig(string $filename): Response
    {
        $doc = $this->documentService->findDocumentByFilename($filename);

        if (!$doc instanceof RstDocument) {
            throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
        }

        $rules   = $this->rectorGenerator->generate($doc);
        $phpCode = $this->rectorGenerator->renderConfig($rules);

        if ($phpCode === '') {
            throw $this->createNotFoundException('No Rector config rules for this document.');
        }

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'rector.php',
        );

        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/matcher-analysis/export-rector-skeleton/{filename}', name: 'rector_export_skeleton', requirements: self::FILENAME_REQUIREMENT)]
    public function exportRectorSkeleton(string $filename): Response
    {
        $doc = $this->documentService->findDocumentByFilename($filename);

        if (!$doc instanceof RstDocument) {
            throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
        }

        $rules         = $this->rectorGenerator->generate($doc);
        $skeletonRules = array_values(array_filter($rules, static fn (RectorRule $r): bool => !$r->isConfig()));

        if ($skeletonRules === []) {
            throw $this->createNotFoundException('No Rector skeleton rules for this document.');
        }

        // Single skeleton → download as PHP file
        if (count($skeletonRules) === 1) {
            $phpCode     = $this->rectorGenerator->renderSkeleton($skeletonRules[0]);
            $disposition = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                pathinfo($filename, PATHINFO_FILENAME) . '-rector.php',
            );

            $response = new Response($phpCode);
            $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
            $response->headers->set('Content-Disposition', $disposition);

            return $response;
        }

        // Multiple skeletons → download as ZIP
        $response = new StreamedResponse(function () use ($skeletonRules): void {
            $zip     = new ZipArchive();
            $tmpFile = tempnam(sys_get_temp_dir(), 'rector_export_');

            if ($tmpFile === false || $zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Failed to create ZIP archive.');
            }

            foreach ($skeletonRules as $rule) {
                $phpCode = $this->rectorGenerator->renderSkeleton($rule);
                $zipName = $this->rectorGenerator->generateClassName($rule) . '.php';
                $zip->addFromString($zipName, $phpCode);
            }

            $zip->close();

            readfile($tmpFile);
            unlink($tmpFile);
        });

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            pathinfo($filename, PATHINFO_FILENAME) . '-rector-skeletons.zip',
        );

        $response->headers->set('Content-Type', 'application/zip');
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

        if (!$doc instanceof RstDocument) {
            throw $this->createNotFoundException(sprintf('Document "%s" not found.', $filename));
        }

        $entries = $this->generator->generate($doc);
        $phpCode = $this->generator->renderPhp($entries);

        return [$doc, $entries, $phpCode];
    }
}
