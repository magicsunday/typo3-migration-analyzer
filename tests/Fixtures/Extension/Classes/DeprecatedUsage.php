<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace TestExtension\Classes;

use TYPO3\CMS\Core\Type\Enumeration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DeprecatedUsage
{
    public function doSomething(): void
    {
        /** @phpstan-ignore classConstant.deprecatedClass */
        GeneralUtility::makeInstance(Enumeration::class);
    }
}
