<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

final readonly class MatcherEntry
{
    /**
     * @param list<string>         $restFiles        RST filenames referenced by this entry
     * @param array<string, mixed> $additionalConfig Other config keys (e.g. numberOfMandatoryArguments)
     */
    public function __construct(
        public string $identifier,
        public MatcherType $matcherType,
        public array $restFiles,
        public array $additionalConfig = [],
    ) {
    }
}
