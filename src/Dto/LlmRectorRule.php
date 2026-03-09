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
 * Represents a Rector rule generated from LLM analysis data.
 *
 * Config-type rules have configEntry set (structured data for RectorConfigRenderer).
 * Skeleton-type rules have rulePhp/testPhp/fixtures set (full Rule class with test).
 *
 * @phpstan-type ConfigEntry array<string, string>
 */
final readonly class LlmRectorRule
{
    /**
     * @param ConfigEntry|null $configEntry Structured config entry for RectorConfigRenderer
     */
    public function __construct(
        public string $filename,
        public RectorRuleType $type,
        public string $ruleClassName,
        public ?array $configEntry,
        public ?string $rulePhp,
        public ?string $testPhp,
        public ?string $fixtureBeforePhp,
        public ?string $fixtureAfterPhp,
    ) {
    }
}
