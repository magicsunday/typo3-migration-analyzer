<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;

use function array_map;
use function count;
use function implode;
use function json_encode;
use function sprintf;
use function str_replace;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Exports scan results in multiple formats: JSON, CSV, and Markdown.
 */
final class ScanReportExporter
{
    /**
     * Export scan results as structured JSON.
     */
    public function toJson(ScanResult $result): string
    {
        $data = [
            'extensionPath' => $result->extensionPath,
            'summary'       => [
                'totalFindings'  => $result->totalFindings(),
                'strongFindings' => $result->strongFindings(),
                'weakFindings'   => $result->weakFindings(),
                'scannedFiles'   => $result->scannedFiles(),
                'filesAffected'  => count($result->filesWithFindings()),
            ],
            'files' => array_map(
                static fn (ScanFileResult $fileResult): array => [
                    'file'     => $fileResult->filePath,
                    'findings' => array_map(
                        static fn (ScanFinding $finding): array => [
                            'line'      => $finding->line,
                            'message'   => $finding->message,
                            'severity'  => $finding->indicator,
                            'code'      => $finding->lineContent,
                            'restFiles' => $finding->restFiles,
                        ],
                        $fileResult->findings,
                    ),
                ],
                $result->filesWithFindings(),
            ),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Export scan results as CSV.
     */
    public function toCsv(ScanResult $result): string
    {
        $lines = ['File,Line,Severity,Message,RST Files'];

        foreach ($result->filesWithFindings() as $fileResult) {
            foreach ($fileResult->findings as $finding) {
                $lines[] = sprintf(
                    '%s,%d,%s,"%s","%s"',
                    $this->escapeCsv($fileResult->filePath),
                    $finding->line,
                    $finding->indicator,
                    $this->escapeCsv($finding->message),
                    $this->escapeCsv(implode('; ', $finding->restFiles)),
                );
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Export scan results as Markdown.
     */
    public function toMarkdown(ScanResult $result): string
    {
        $lines   = [];
        $lines[] = sprintf('# Scan Report: %s', $result->extensionPath);
        $lines[] = '';
        $lines[] = sprintf(
            '**%d** findings in **%d** files (%d scanned), **%d** strong / **%d** weak',
            $result->totalFindings(),
            count($result->filesWithFindings()),
            $result->scannedFiles(),
            $result->strongFindings(),
            $result->weakFindings(),
        );
        $lines[] = '';

        foreach ($result->filesWithFindings() as $fileResult) {
            $lines[] = sprintf('## %s', $fileResult->filePath);
            $lines[] = '';
            $lines[] = '| Line | Severity | Message | RST Files |';
            $lines[] = '|------|----------|---------|-----------|';

            foreach ($fileResult->findings as $finding) {
                $lines[] = sprintf(
                    '| %d | %s | %s | %s |',
                    $finding->line,
                    $finding->indicator,
                    $finding->message,
                    implode(', ', $finding->restFiles),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Escape a value for CSV output (double quotes).
     */
    private function escapeCsv(string $value): string
    {
        return str_replace('"', '""', $value);
    }
}
