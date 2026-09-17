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
     * @param string $value       The value to sanitize.
     * @param string $replacement The string each control character is replaced with.
     */
    public static function strip(string $value, string $replacement = ''): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', $replacement, $value) ?? $value;
    }
}
