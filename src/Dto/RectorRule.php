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
 * Represents a generated Rector rule -- either a rector.php config entry or a Rule class skeleton.
 */
final readonly class RectorRule
{
    public function __construct(
        public RectorRuleType $type,
        public CodeReference $source,
        public ?CodeReference $target,
        public string $description,
        public string $rstFilename,
    ) {
    }

    /**
     * Whether this rule can be expressed as a rector.php configuration entry.
     */
    public function isConfig(): bool
    {
        return $this->type !== RectorRuleType::Skeleton;
    }
}
