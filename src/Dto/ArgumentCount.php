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
 * Represents the detected argument count for a method signature.
 */
final readonly class ArgumentCount
{
    public function __construct(
        public int $numberOfMandatoryArguments,
        public int $maximumNumberOfArguments,
    ) {
    }

    /**
     * Convert to TYPO3 Extension Scanner matcher config format.
     *
     * @return array{numberOfMandatoryArguments: int, maximumNumberOfArguments: int}
     */
    public function toConfigArray(): array
    {
        return [
            'numberOfMandatoryArguments' => $this->numberOfMandatoryArguments,
            'maximumNumberOfArguments'   => $this->maximumNumberOfArguments,
        ];
    }
}
