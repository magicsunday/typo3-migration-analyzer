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
 * Represents a single code block extracted from an RST document.
 */
final readonly class CodeBlock
{
    public function __construct(
        public string $language,
        public string $code,
        public ?string $label,
    ) {
    }
}
