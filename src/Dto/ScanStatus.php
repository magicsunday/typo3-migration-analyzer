<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

enum ScanStatus: string
{
    case FullyScanned     = 'fully_scanned';
    case PartiallyScanned = 'partially_scanned';
    case NotScanned       = 'not_scanned';
}
