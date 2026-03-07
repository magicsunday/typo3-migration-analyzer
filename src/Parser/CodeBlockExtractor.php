<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Parser;

use App\Dto\CodeBlock;

use function array_pop;
use function count;
use function explode;
use function implode;
use function min;
use function preg_match;
use function rtrim;
use function strlen;
use function substr;
use function trim;

/**
 * Extracts code blocks from RST document content.
 *
 * Parses `.. code-block:: <language>` directives and collects
 * their indented content. Recognises RST subsection headers
 * ("Before"/"After"/"Vorher"/"Nachher") and assigns them as labels.
 */
final class CodeBlockExtractor
{
    /** Valid RST section underline characters: = - ` : . ' " ~ ^ _ * + # */
    private const string RST_UNDERLINE_PATTERN = '/^[=\-`:\.\'"~^_*+#]{3,}$/';

    /**
     * Extract all code blocks from the given RST text.
     *
     * @return list<CodeBlock>
     */
    public function extract(?string $rstText): array
    {
        if ($rstText === null || $rstText === '') {
            return [];
        }

        $lines        = explode("\n", $rstText);
        $lineCount    = count($lines);
        $blocks       = [];
        $currentLabel = null;
        $i            = 0;

        while ($i < $lineCount) {
            // Detect RST subsection header: text line followed by underline
            if (
                $i + 1 < $lineCount
                && trim($lines[$i]) !== ''
                && preg_match(self::RST_UNDERLINE_PATTERN, trim($lines[$i + 1])) === 1
            ) {
                $headerText = trim($lines[$i]);

                $currentLabel = preg_match('/^(Before|After|Vorher|Nachher)$/i', $headerText) === 1 ? $headerText : null;

                // Skip header and underline
                $i += 2;

                continue;
            }

            // Detect code-block directive
            if (preg_match('/^\.\.\s+code-block::\s+(\w+)/', trim($lines[$i]), $matches) === 1) {
                $language = $matches[1];
                ++$i;

                // Skip blank lines after directive
                while ($i < $lineCount && trim($lines[$i]) === '') {
                    ++$i;
                }

                // Collect indented lines (3+ spaces)
                $codeLines = [];

                while ($i < $lineCount) {
                    $line = $lines[$i];

                    // Indented line (3+ spaces)
                    if (preg_match('/^ {3,}/', $line) === 1) {
                        $codeLines[] = $line;
                        ++$i;

                        continue;
                    }

                    // Blank line within code block — include if more indented lines follow
                    if (trim($line) === '') {
                        // Look ahead for more indented lines
                        $hasMoreCode = false;

                        for ($j = $i + 1; $j < $lineCount; ++$j) {
                            if (preg_match('/^ {3,}/', $lines[$j]) === 1) {
                                $hasMoreCode = true;

                                break;
                            }

                            if (trim($lines[$j]) !== '') {
                                break;
                            }
                        }

                        if ($hasMoreCode) {
                            $codeLines[] = '';
                            ++$i;

                            continue;
                        }
                    }

                    // Non-blank, non-indented line — end of code block
                    break;
                }

                // Remove trailing blank lines
                while ($codeLines !== [] && trim($codeLines[count($codeLines) - 1]) === '') {
                    array_pop($codeLines);
                }

                if ($codeLines !== []) {
                    $code     = $this->stripCommonIndent($codeLines);
                    $blocks[] = new CodeBlock($language, $code, $currentLabel);
                }

                continue;
            }

            ++$i;
        }

        return $blocks;
    }

    /**
     * Remove the common leading whitespace from all non-blank lines.
     *
     * @param list<string> $lines
     */
    private function stripCommonIndent(array $lines): string
    {
        $minIndent = PHP_INT_MAX;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $stripped  = rtrim($line);
            $indent    = strlen($stripped) - strlen(ltrim($stripped));
            $minIndent = min($minIndent, $indent);
        }

        if ($minIndent === PHP_INT_MAX || $minIndent === 0) {
            return implode("\n", $lines);
        }

        $stripped = [];

        foreach ($lines as $line) {
            $stripped[] = trim($line) === '' ? '' : substr($line, $minIndent);
        }

        return implode("\n", $stripped);
    }
}
