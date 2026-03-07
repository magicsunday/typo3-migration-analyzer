<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use App\Scanner\ExtensionScanner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function array_map;
use function count;
use function is_dir;

final class ScanController extends AbstractController
{
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

        return $this->render('scan/result.html.twig', [
            'result' => $result,
        ]);
    }

    #[Route('/scan/export-json', name: 'scan_export_json', methods: ['GET'])]
    public function exportJson(Request $request): Response
    {
        $extensionPath = $request->query->getString('path');

        if ($extensionPath === '' || !is_dir($extensionPath)) {
            $this->addFlash('danger', 'Der angegebene Pfad existiert nicht oder ist kein Verzeichnis.');

            return $this->redirectToRoute('scan_index');
        }

        $result   = $this->scanner->scan($extensionPath);
        $data     = $this->buildJsonExport($result);
        $response = new JsonResponse($data);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'scan-result.json',
        );

        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    /**
     * Builds a JSON-serializable array from the scan result.
     *
     * @return array<string, mixed>
     */
    private function buildJsonExport(ScanResult $result): array
    {
        return [
            'extensionPath' => $result->extensionPath,
            'scannedFiles'  => $result->scannedFiles(),
            'totalFindings' => $result->totalFindings(),
            'filesAffected' => count($result->filesWithFindings()),
            'files'         => array_map(
                static fn (ScanFileResult $fileResult): array => [
                    'file'     => $fileResult->filePath,
                    'findings' => array_map(
                        static fn (ScanFinding $finding): array => [
                            'line'        => $finding->line,
                            'message'     => $finding->message,
                            'indicator'   => $finding->indicator,
                            'lineContent' => $finding->lineContent,
                            'restFiles'   => $finding->restFiles,
                        ],
                        $fileResult->findings,
                    ),
                ],
                $result->filesWithFindings(),
            ),
        ];
    }
}
