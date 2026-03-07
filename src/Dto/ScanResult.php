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
use function array_map;
use function array_sum;
use function array_values;
use function count;

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
}
