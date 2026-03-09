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
 * Represents the current LLM configuration (provider, model, API key, prompt).
 */
final readonly class LlmConfiguration
{
    public function __construct(
        public LlmProvider $provider,
        public string $modelId,
        public string $apiKey,
        public string $analysisPrompt,
        public string $promptVersion,
    ) {
    }
}
