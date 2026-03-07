<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

enum RectorRuleType: string
{
    case RenameClass         = 'rename_class';
    case RenameMethod        = 'rename_method';
    case RenameStaticMethod  = 'rename_static_method';
    case RenameClassConstant = 'rename_class_constant';
    case Skeleton            = 'skeleton';
}
