<?php

declare(strict_types=1);

namespace App\Dto;

enum CodeReferenceType: string
{
    case ClassName = 'class_name';
    case InstanceMethod = 'instance_method';
    case StaticMethod = 'static_method';
    case Property = 'property';
    case ClassConstant = 'class_constant';
}
