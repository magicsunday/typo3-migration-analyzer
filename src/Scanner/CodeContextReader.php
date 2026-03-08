<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use App\Dto\ContextLine;
use SplFileObject;

use function is_file;
use function is_string;
use function max;
use function min;
use function rtrim;

/**
 * Reads surrounding lines of code from a source file for context display.
 */
final readonly class CodeContextReader
{
    /**
     * Read lines surrounding a target line number.
     *
     * @param string $filePath   Absolute path to the source file
     * @param int    $lineNumber The target line number (1-based)
     * @param int    $radius     Number of lines before and after to include
     *
     * @return list<ContextLine>
     */
    public function readContext(string $filePath, int $lineNumber, int $radius = 3): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        $file = new SplFileObject($filePath);

        // Count total lines
        $file->seek(PHP_INT_MAX);

        $totalLines = $file->key() + 1;

        $startLine = max(1, $lineNumber - $radius);
        $endLine   = min($totalLines, $lineNumber + $radius);

        $lines = [];

        for ($i = $startLine; $i <= $endLine; ++$i) {
            $file->seek($i - 1);
            $content = $file->current();

            $lines[] = new ContextLine(
                number: $i,
                content: is_string($content) ? rtrim($content) : '',
                isHighlighted: $i === $lineNumber,
            );
        }

        return $lines;
    }
}
