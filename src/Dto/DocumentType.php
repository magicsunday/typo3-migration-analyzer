<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

enum DocumentType: string
{
    case Deprecation = 'deprecation';
    case Breaking    = 'breaking';
    case Feature     = 'feature';
    case Important   = 'important';
}
