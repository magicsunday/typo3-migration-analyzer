<?php

declare(strict_types=1);

namespace App\Dto;

enum DocumentType: string
{
    case Deprecation = 'deprecation';
    case Breaking = 'breaking';
    case Feature = 'feature';
    case Important = 'important';
}
