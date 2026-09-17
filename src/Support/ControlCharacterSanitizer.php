<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Support;

use function preg_replace;

/**
 * Strips control characters (e.g. terminal escape sequences) from strings
 * that reach a terminal or a downstream parser without their own escaping,
 * such as a value read from a scanned file path or an LLM response.
 */
final class ControlCharacterSanitizer
{
    private function __construct()
    {
    }

    /**
     * Remove ASCII control characters (0x00-0x1F, 0x7F) from a value before
     * it reaches a terminal or a downstream parser.
     *
     * @param string $value       The value to sanitize.
     * @param string $replacement The string each control character is replaced with.
     *
     * @return string The sanitized value.
     */
    public static function strip(string $value, string $replacement = ''): string
    {
        // preg_replace() returns null only on a PCRE engine failure (e.g. the
        // backtrack limit). This fixed, backtracking-free character class
        // cannot trigger that, so the fallback just returns the original,
        // unsanitized value.
        return preg_replace('/[\x00-\x1F\x7F]/', $replacement, $value) ?? $value;
    }
}
