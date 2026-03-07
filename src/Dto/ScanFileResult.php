<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

/**
 * Aggregated scan results for a single PHP file.
 */
final readonly class ScanFileResult
{
    /**
     * @param list<ScanFinding> $findings All findings detected in this file
     */
    public function __construct(
        public string $filePath,
        public array $findings,
        public bool $isFileIgnored,
        public int $effectiveCodeLines,
        public int $ignoredLines,
    ) {
    }
}
