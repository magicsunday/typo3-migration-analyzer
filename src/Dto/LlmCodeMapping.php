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
 * Represents a single code mapping extracted by LLM analysis.
 */
final readonly class LlmCodeMapping
{
    /**
     * @param string      $old  The old class, method, constant, or path
     * @param string|null $new  The replacement, or null if removed without replacement
     * @param string      $type One of: class_rename, method_rename, constant_rename,
     *                          argument_change, method_removal, class_removal,
     *                          hook_to_event, typoscript_change, tca_change, behavior_change
     */
    public function __construct(
        public string $old,
        public ?string $new,
        public string $type,
    ) {
    }
}
