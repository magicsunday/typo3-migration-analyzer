<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CodeReference;
use App\Dto\MigrationMapping;

use function min;
use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * Extracts old->new API mappings from RST migration and description text.
 *
 * Detects patterns like "Replace :php:`\Old\Class` with :php:`\New\Class`" and converts
 * them into structured MigrationMapping DTOs with source, target, and confidence.
 */
final class MigrationMappingExtractor
{
    /**
     * Mapping patterns with [regex, sourceGroup, targetGroup, confidence].
     *
     * @var list<array{string, int, int, float}>
     */
    private const array MAPPING_PATTERNS = [
        // "Replace :php:`Old` with/by :php:`New`"
        ['/\b[Rr]eplace\b.*?:php:`([^`]+)`.*?\b(?:with|by)\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
        // ":php:`Old` has been/was renamed to :php:`New`"
        ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+renamed\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
        // "Use :php:`New` instead of :php:`Old`" (note: reversed order)
        ['/\b[Uu]se\b.*?:php:`([^`]+)`.*?\binstead\s+of\b.*?:php:`([^`]+)`/s', 2, 1, 0.9],
        // "Migrate [from] :php:`Old` to :php:`New`"
        ['/\b[Mm]igrate\b.*?(?:from\s+)?:php:`([^`]+)`.*?\bto\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
        // ":php:`Old` has been/was moved to :php:`New`"
        ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+moved\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 1.0],
        // ":php:`Old` has been/was changed to :php:`New`"
        ['/:php:`([^`]+)`.*?\b(?:has been|was)\s+changed\s+to\b.*?:php:`([^`]+)`/s', 1, 2, 0.9],
        // ":php:`Old` (has been|was|should be|can be) replaced (by|with) :php:`New`"
        ['/:php:`([^`]+)`.*?\b(?:has been|was|should be|can be)\s+replaced\s+(?:by|with)\b.*?:php:`([^`]+)`/s', 1, 2, 0.9],
        // ":php:`Old` is now available via :php:`New`"
        ['/:php:`([^`]+)`.*?\bis\s+now\s+available\s+via\b.*?:php:`([^`]+)`/s', 1, 2, 0.8],
        // Bare ":php:`Old` to :php:`New`" (no keyword prefix — lowest priority, MUST be last)
        ['/:php:`([^`]+)`\s+to\s+:php:`([^`]+)`/', 1, 2, 0.9],
    ];

    /**
     * Extract old->new API mappings from RST migration and description text.
     *
     * Scans migration text first, then description text, deduplicating across both
     * by source+target key and keeping the first (highest confidence) match.
     *
     * @return list<MigrationMapping>
     */
    public function extract(?string $migrationText, ?string $descriptionText = null): array
    {
        $mappings = [];
        $seen     = [];

        foreach ([$migrationText, $descriptionText] as $text) {
            if ($text === null) {
                continue;
            }

            if ($text === '') {
                continue;
            }

            foreach (self::MAPPING_PATTERNS as [$pattern, $sourceGroup, $targetGroup, $confidence]) {
                if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $match) {
                    $source = CodeReference::fromPhpRole($match[$sourceGroup]);
                    $target = CodeReference::fromPhpRole($match[$targetGroup]);

                    if (!$source instanceof CodeReference) {
                        continue;
                    }

                    if (!$target instanceof CodeReference) {
                        continue;
                    }

                    $key = $source->className . '::' . ($source->member ?? '')
                        . '->' . $target->className . '::' . ($target->member ?? '');

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $effectiveConfidence = $confidence * min(
                        $source->resolutionConfidence,
                        $target->resolutionConfidence,
                    );

                    $seen[$key] = true;
                    $mappings[] = new MigrationMapping($source, $target, $effectiveConfidence);
                }
            }
        }

        return $mappings;
    }
}
