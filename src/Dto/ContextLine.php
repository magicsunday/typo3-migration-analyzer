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
 * A single line of source code with its line number and highlight status.
 */
final readonly class ContextLine
{
    public function __construct(
        public int $number,
        public string $content,
        public bool $isHighlighted = false,
    ) {
    }
}
