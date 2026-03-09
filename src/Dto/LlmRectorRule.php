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
 * Config-type rules have configPhp set (rector.php entry).
 * Skeleton-type rules have rulePhp/testPhp/fixtures set (full Rule class with test).
 */
final readonly class LlmRectorRule
{
    public function __construct(
        public string $filename,
        public RectorRuleType $type,
        public string $ruleClassName,
        public ?string $configPhp,
        public ?string $rulePhp,
        public ?string $testPhp,
        public ?string $fixtureBeforePhp,
        public ?string $fixtureAfterPhp,
    ) {
    }
}
