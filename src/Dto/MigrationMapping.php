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
 * Represents a detected old->new API mapping from an RST migration section.
 */
final readonly class MigrationMapping
{
    public function __construct(
        public CodeReference $source,
        public CodeReference $target,
        public float $confidence,
    ) {
    }
}
