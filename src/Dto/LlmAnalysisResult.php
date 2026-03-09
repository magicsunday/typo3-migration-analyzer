<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Dto;

use function array_filter;
use function array_map;
use function implode;
use function is_string;

/**
 * Represents the result of an LLM-based analysis of an RST document.
 */
final readonly class LlmAnalysisResult
{
    /**
     * @param list<string>             $migrationSteps     Concrete steps for migration
     * @param list<string>             $affectedAreas      Affected areas (e.g. "PHP", "Fluid", "TCA")
     * @param list<string>             $affectedComponents TYPO3 components (e.g. "Extbase", "Backend", "PSR-14 Events")
     * @param list<LlmCodeMapping>     $codeMappings       Structured old→new code mappings
     * @param LlmRectorAssessment|null $rectorAssessment   Rector automation feasibility, or null
     */
    public function __construct(
        public string $filename,
        public string $modelId,
        public string $promptVersion,
        public int $score,
        public AutomationGrade $automationGrade,
        public string $summary,
        public string $reasoning,
        /** @var list<string> */
        public array $migrationSteps,
        /** @var list<string> */
        public array $affectedAreas,
        /** @var list<string> */
        public array $affectedComponents,
        /** @var list<LlmCodeMapping> */
        public array $codeMappings,
        public ?LlmRectorAssessment $rectorAssessment,
        public int $tokensInput,
        public int $tokensOutput,
        public int $durationMs,
        public string $createdAt,
    ) {
    }

    /**
     * Normalize an array of mixed values (strings or objects) to a flat string list.
     *
     * LLMs sometimes return structured objects (e.g. {"step": 1, "description": "..."})
     * instead of plain strings. This flattens them by joining string values.
     *
     * @param list<string|array<string, mixed>> $items
     *
     * @return list<string>
     */
    public static function normalizeToStrings(array $items): array
    {
        return array_map(
            static function (string|array $item): string {
                if (is_string($item)) {
                    return $item;
                }

                return implode(': ', array_filter($item, is_string(...)));
            },
            $items,
        );
    }
}
