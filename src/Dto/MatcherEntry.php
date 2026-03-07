<?php

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
