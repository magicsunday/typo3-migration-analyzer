<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Analyzer\ActionPlanGenerator;
use App\Dto\AutomationGrade;
use App\Dto\ScanResult;
use App\Generator\RectorRuleGenerator;
use App\Scanner\ExtensionScanner;
use App\Scanner\GitRepositoryHandler;
use App\Scanner\ScanReportExporter;
use App\Scanner\ZipUploadHandler;
use App\Service\DocumentService;
use App\Service\VersionRangeProvider;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_values;
use function is_dir;
use function trim;

final class ScanController extends AbstractController
{
    private const string SESSION_KEY_SCAN_RESULT = 'scan_result';

    private const string SESSION_KEY_SCAN_SOURCE = 'scan_source';

    public function __construct(
        private readonly ExtensionScanner $scanner,
        private readonly DocumentService $documentService,
        private readonly VersionRangeProvider $versionRangeProvider,
        private readonly ActionPlanGenerator $actionPlanGenerator,
        private readonly RectorRuleGenerator $rectorGenerator,
    ) {
    }

    #[Route('/scan', name: 'scan_index')]
    public function index(): Response
    {
        return $this->render('scan/index.html.twig', [
            'versionRange'  => $this->documentService->getVersionRange(),
            'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
        ]);
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
            'result'        => $result,
            'versionRange'  => $this->documentService->getVersionRange(),
            'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
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
            'result'        => $result,
            'versionRange'  => $this->documentService->getVersionRange(),
            'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
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
            'result'        => $result,
            'versionRange'  => $this->documentService->getVersionRange(),
            'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
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
     * Generate and display a prioritized action plan based on scan results.
     */
    #[Route('/scan/action-plan', name: 'scan_action_plan')]
    public function actionPlan(Request $request): Response
    {
        $result = $this->getSessionResult($request);

        if (!$result instanceof ScanResult) {
            return $this->redirectToRoute('scan_index');
        }

        $documents = array_values($this->documentService->getDocuments());
        $plan      = $this->actionPlanGenerator->generate($result, $documents);

        return $this->render('scan/action-plan.html.twig', [
            'plan'          => $plan,
            'result'        => $result,
            'versionRange'  => $this->documentService->getVersionRange(),
            'majorVersions' => $this->versionRangeProvider->getAvailableMajorVersions(),
        ]);
    }

    /**
     * Export a combined Rector config with all automatable rules from the action plan.
     */
    #[Route('/scan/export-rector-config', name: 'scan_export_rector_config')]
    public function exportRectorConfig(Request $request): Response
    {
        $result = $this->getSessionResult($request);

        if (!$result instanceof ScanResult) {
            return $this->redirectToRoute('scan_index');
        }

        $documents = array_values($this->documentService->getDocuments());
        $plan      = $this->actionPlanGenerator->generate($result, $documents);

        // Collect all config rules from fully and partially automatable items
        $allConfigRules = [];

        foreach ($plan->items as $item) {
            if ($item->automationGrade === AutomationGrade::Manual) {
                continue;
            }

            foreach ($item->rectorRules as $rule) {
                if ($rule->isConfig()) {
                    $allConfigRules[] = $rule;
                }
            }
        }

        if ($allConfigRules === []) {
            $this->addFlash('warning', 'Keine automatisierbaren Rector-Rules gefunden.');

            return $this->redirectToRoute('scan_action_plan');
        }

        $phpCode = $this->rectorGenerator->renderConfig($allConfigRules);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'rector.php',
        );

        $response = new Response($phpCode);
        $response->headers->set('Content-Type', 'application/x-php; charset=UTF-8');
        $response->headers->set('Content-Disposition', $disposition);

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
