<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ScanResult;
use App\Scanner\ExtensionScanner;
use App\Scanner\GitRepositoryHandler;
use App\Scanner\ScanReportExporter;
use App\Scanner\ZipUploadHandler;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_dir;
use function trim;

final class ScanController extends AbstractController
{
    private const string SESSION_KEY_SCAN_RESULT = 'scan_result';

    private const string SESSION_KEY_SCAN_SOURCE = 'scan_source';

    public function __construct(
        private readonly ExtensionScanner $scanner,
    ) {
    }

    #[Route('/scan', name: 'scan_index')]
    public function index(): Response
    {
        return $this->render('scan/index.html.twig');
    }

    #[Route('/scan/run', name: 'scan_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        $extensionPath = $request->request->getString('extension_path');

        if ($extensionPath === '' || !is_dir($extensionPath)) {
            $this->addFlash('danger', 'Der angegebene Pfad existiert nicht oder ist kein Verzeichnis.');

            return $this->redirectToRoute('scan_index');
        }

        $result = $this->scanner->scan($extensionPath);

        $this->storeScanResult($request, $result, $extensionPath);

        return $this->render('scan/result.html.twig', [
            'result' => $result,
        ]);
    }

    /**
     * Handle ZIP file upload, extract, scan, and clean up.
     */
    #[Route('/scan/upload', name: 'scan_upload', methods: ['POST'])]
    public function upload(Request $request, ZipUploadHandler $uploadHandler): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('extension_zip');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('danger', 'Bitte eine gültige ZIP-Datei auswählen.');

            return $this->redirectToRoute('scan_index');
        }

        try {
            $extractedPath = $uploadHandler->extract($file);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('scan_index');
        }

        try {
            $result = $this->scanner->scan($extractedPath);

            $this->storeScanResult($request, $result, $file->getClientOriginalName());
        } finally {
            $uploadHandler->cleanup($extractedPath);
        }

        return $this->render('scan/result.html.twig', [
            'result' => $result,
        ]);
    }

    /**
     * Handle Git repository URL, clone, scan, and clean up.
     */
    #[Route('/scan/clone', name: 'scan_clone', methods: ['POST'])]
    public function clone(Request $request, GitRepositoryHandler $gitHandler): Response
    {
        $repositoryUrl = trim($request->request->getString('repository_url'));

        $session      = $request->getSession();
        $cachedResult = $session->get(self::SESSION_KEY_SCAN_RESULT);
        $cachedSource = $session->get(self::SESSION_KEY_SCAN_SOURCE);

        if ($cachedResult instanceof ScanResult && $cachedSource === $repositoryUrl) {
            return $this->render('scan/result.html.twig', [
                'result' => $cachedResult,
            ]);
        }

        try {
            $gitHandler->validate($repositoryUrl);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('scan_index');
        }

        try {
            $clonedPath = $gitHandler->clone($repositoryUrl);
        } catch (RuntimeException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('scan_index');
        }

        try {
            $result = $this->scanner->scan($clonedPath);

            $this->storeScanResult($request, $result, $repositoryUrl);
        } finally {
            $gitHandler->cleanup($clonedPath);
        }

        return $this->render('scan/result.html.twig', [
            'result' => $result,
        ]);
    }

    /**
     * Export scan results as structured JSON.
     */
    #[Route('/scan/export-json', name: 'scan_export_json', methods: ['GET'])]
    public function exportJson(Request $request, ScanReportExporter $exporter): Response
    {
        $result = $this->getSessionResult($request);

        if (!$result instanceof ScanResult) {
            return $this->redirectToRoute('scan_index');
        }

        $response = new Response($exporter->toJson($result));
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.json'),
        );

        return $response;
    }

    /**
     * Export scan results as CSV.
     */
    #[Route('/scan/export-csv', name: 'scan_export_csv', methods: ['GET'])]
    public function exportCsv(Request $request, ScanReportExporter $exporter): Response
    {
        $result = $this->getSessionResult($request);

        if (!$result instanceof ScanResult) {
            return $this->redirectToRoute('scan_index');
        }

        $response = new Response($exporter->toCsv($result));
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.csv'),
        );

        return $response;
    }

    /**
     * Export scan results as Markdown.
     */
    #[Route('/scan/export-markdown', name: 'scan_export_markdown', methods: ['GET'])]
    public function exportMarkdown(Request $request, ScanReportExporter $exporter): Response
    {
        $result = $this->getSessionResult($request);

        if (!$result instanceof ScanResult) {
            return $this->redirectToRoute('scan_index');
        }

        $response = new Response($exporter->toMarkdown($result));
        $response->headers->set('Content-Type', 'text/markdown; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'scan-result.md'),
        );

        return $response;
    }

    /**
     * Store the scan result and its source identifier in the session.
     */
    private function storeScanResult(Request $request, ScanResult $result, string $source): void
    {
        $session = $request->getSession();
        $session->set(self::SESSION_KEY_SCAN_RESULT, $result);
        $session->set(self::SESSION_KEY_SCAN_SOURCE, $source);
    }

    /**
     * Retrieve the cached scan result from the session, or null if none exists.
     */
    private function getSessionResult(Request $request): ?ScanResult
    {
        $result = $request->getSession()->get(self::SESSION_KEY_SCAN_RESULT);

        if (!$result instanceof ScanResult) {
            $this->addFlash('danger', 'Kein Scan-Ergebnis vorhanden. Bitte zuerst einen Scan durchführen.');

            return null;
        }

        return $result;
    }
}
