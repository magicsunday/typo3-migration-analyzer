<?php

declare(strict_types=1);

namespace App\Dto;

enum ScanStatus: string
{
    case FullyScanned = 'fully_scanned';
    case PartiallyScanned = 'partially_scanned';
    case NotScanned = 'not_scanned';
}
