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
 * Represents the result of an LLM-based analysis of an RST document.
 */
final readonly class LlmAnalysisResult
{
    /**
     * @param list<string> $migrationSteps Concrete steps for migration
     * @param list<string> $affectedAreas  Affected areas (e.g. "PHP", "Fluid", "TCA")
     */
    public function __construct(
        public string $filename,
        public string $modelId,
        public string $promptVersion,
        public int $score,
        public AutomationGrade $automationGrade,
        public string $summary,
        /** @var list<string> */
        public array $migrationSteps,
        /** @var list<string> */
        public array $affectedAreas,
        public int $tokensInput,
        public int $tokensOutput,
        public int $durationMs,
        public string $createdAt,
    ) {
    }
}
