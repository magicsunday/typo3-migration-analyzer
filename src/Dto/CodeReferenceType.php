<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

enum CodeReferenceType: string
{
    case ClassName         = 'class_name';
    case ShortClassName    = 'short_class_name';
    case InstanceMethod    = 'instance_method';
    case StaticMethod      = 'static_method';
    case UnqualifiedMethod = 'unqualified_method';
    case Property          = 'property';
    case ClassConstant     = 'class_constant';
    case ConfigKey         = 'config_key';
}
