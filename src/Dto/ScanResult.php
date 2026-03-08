<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use function array_filter;
use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function count;
use function ksort;

/**
 * Complete scan result for a TYPO3 extension.
 */
final readonly class ScanResult
{
    /**
     * @param list<ScanFileResult> $fileResults Results for each scanned PHP file
     */
    public function __construct(
        public string $extensionPath,
        public array $fileResults,
    ) {
    }

    /**
     * Returns the total number of findings across all scanned files.
     */
    public function totalFindings(): int
    {
        return array_sum(
            array_map(
                static fn (ScanFileResult $fileResult): int => count($fileResult->findings),
                $this->fileResults,
            ),
        );
    }

    /**
     * Returns the number of scanned files.
     */
    public function scannedFiles(): int
    {
        return count($this->fileResults);
    }

    /**
     * Returns the count of strong (high-confidence) findings.
     */
    public function strongFindings(): int
    {
        $count = 0;

        foreach ($this->fileResults as $fileResult) {
            foreach ($fileResult->findings as $finding) {
                if ($finding->isStrong()) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * Returns the count of weak (low-confidence) findings.
     */
    public function weakFindings(): int
    {
        return $this->totalFindings() - $this->strongFindings();
    }

    /**
     * Returns only the file results that contain at least one finding.
     *
     * @return list<ScanFileResult>
     */
    public function filesWithFindings(): array
    {
        return array_values(
            array_filter(
                $this->fileResults,
                static fn (ScanFileResult $fileResult): bool => $fileResult->findings !== [],
            ),
        );
    }

    /**
     * Returns a deduplicated list of all RST filenames referenced by findings.
     *
     * @return list<string>
     */
    public function uniqueRestFiles(): array
    {
        $files = [];

        foreach ($this->fileResults as $fileResult) {
            foreach ($fileResult->findings as $finding) {
                foreach ($finding->restFiles as $restFile) {
                    $files[$restFile] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * Group all findings by their RST file reference.
     *
     * @return array<string, list<array{file: string, finding: ScanFinding}>>
     */
    public function findingsGroupedByRestFile(): array
    {
        $grouped = [];

        foreach ($this->fileResults as $fileResult) {
            foreach ($fileResult->findings as $finding) {
                foreach ($finding->restFiles as $restFile) {
                    $grouped[$restFile][] = [
                        'file'    => $fileResult->filePath,
                        'finding' => $finding,
                    ];
                }
            }
        }

        ksort($grouped);

        return $grouped;
    }
}
