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

use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * Extracts old->new API mappings from RST migration text.
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
    ];

    /**
     * Extract old->new API mappings from RST migration text.
     *
     * Deduplicates by source+target key, keeping the first (highest confidence) match.
     *
     * @return list<MigrationMapping>
     */
    public function extract(?string $migrationText): array
    {
        if ($migrationText === null || $migrationText === '') {
            return [];
        }

        $mappings = [];
        $seen     = [];

        foreach (self::MAPPING_PATTERNS as [$pattern, $sourceGroup, $targetGroup, $confidence]) {
            if (preg_match_all($pattern, $migrationText, $matches, PREG_SET_ORDER) === 0) {
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

                $seen[$key] = true;
                $mappings[] = new MigrationMapping($source, $target, $confidence);
            }
        }

        return $mappings;
    }
}
