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
 * Represents a single finding from the TYPO3 Extension Scanner.
 */
final readonly class ScanFinding
{
    /**
     * @param list<string> $restFiles RST filenames associated with this finding
     */
    public function __construct(
        public int $line,
        public string $message,
        public string $indicator,
        public string $lineContent,
        public array $restFiles,
    ) {
    }

    /**
     * Whether this finding is a strong match (high confidence).
     */
    public function isStrong(): bool
    {
        return $this->indicator === 'strong';
    }
}
