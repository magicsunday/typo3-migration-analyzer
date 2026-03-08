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
 * Classifies how automatable a migration action is.
 */
enum AutomationGrade: string
{
    /** Rector can handle the migration completely. */
    case Full = 'full';

    /** Rector handles part of the migration; manual work remains. */
    case Partial = 'partial';

    /** No Rector support; entirely manual migration. */
    case Manual = 'manual';
}
