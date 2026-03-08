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
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

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
        // ":php:`New` instead of :php:`Old`" (without "Use" prefix, reversed: first=new, second=old)
        ['/:php:`([^`]+)`\s+instead\s+of\s+:php:`([^`]+)`/', 2, 1, 0.8],
        // ":php:`Old` deprecated/removed ... Use :php:`New`" (cross-sentence, same paragraph)
        ['/:php:`([^`]+)`.*?\b(?:has been|was|is)\s+(?:removed|deprecated)\b.*?\b[Uu]se\b\s+:php:`([^`]+)`/s', 1, 2, 0.7],
        // ":php:`Old` has been/was/is superseded by :php:`New`"
        ['/:php:`([^`]+)`.*?\b(?:has been|was|is)\s+superseded\s+by\b.*?:php:`([^`]+)`/s', 1, 2, 0.9],
        // ":php:`Old` no longer extends :php:`New`"
        ['/:php:`([^`]+)`.*?\bno\s+longer\s+extends\b.*?:php:`([^`]+)`/s', 1, 2, 0.7],
        // Bare ":php:`Old` to :php:`New`" (no keyword prefix — lowest priority, MUST be last)
        ['/:php:`([^`]+)`\s+to\s+:php:`([^`]+)`/', 1, 2, 0.9],
    ];

    /**
     * Backtick patterns using regular code references instead of :php: roles.
     *
     * Only matches where both source and target look like PHP code are kept.
     *
     * @var list<array{string, int, int, float}>
     */
    private const array BACKTICK_PATTERNS = [
        // "`Old` has been renamed/replaced/moved to/by/with `New`" (single line only to avoid cross-bullet matches)
        ['/`([^`]+)`.{0,60}\b(?:has been|was|is)\s+(?:renamed?|replaced?|moved?)\s+(?:to|by|with)\b.{0,60}`([^`]+)`/', 1, 2, 0.6],
        // "Replace `Old` with/by `New`" (single line only)
        ['/\b[Rr]eplace\b.{0,60}`([^`]+)`.{0,40}\b(?:with|by)\b.{0,60}`([^`]+)`/', 1, 2, 0.6],
        // "`Old` to `New`" (bare connector)
        ['/`([^`]+)`\s+to\s+`([^`]+)`/', 1, 2, 0.5],
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

            // Process :php: role patterns
            foreach (self::MAPPING_PATTERNS as [$pattern, $sourceGroup, $targetGroup, $confidence]) {
                $this->processPattern($pattern, $sourceGroup, $targetGroup, $confidence, $text, $mappings, $seen);
            }

            // Process backtick patterns (only where both values look like PHP code)
            foreach (self::BACKTICK_PATTERNS as [$pattern, $sourceGroup, $targetGroup, $confidence]) {
                $this->processBacktickPattern($pattern, $sourceGroup, $targetGroup, $confidence, $text, $mappings, $seen);
            }
        }

        return $mappings;
    }

    /**
     * Process a single :php: role pattern against the given text.
     *
     * @param list<MigrationMapping> $mappings
     * @param array<string, true>    $seen
     */
    private function processPattern(
        string $pattern,
        int $sourceGroup,
        int $targetGroup,
        float $confidence,
        string $text,
        array &$mappings,
        array &$seen,
    ): void {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($matches as $match) {
            $this->addMapping($match[$sourceGroup], $match[$targetGroup], $confidence, $mappings, $seen);
        }
    }

    /**
     * Process a single backtick pattern against the given text.
     *
     * Only creates mappings where both source and target values look like PHP code.
     *
     * @param list<MigrationMapping> $mappings
     * @param array<string, true>    $seen
     */
    private function processBacktickPattern(
        string $pattern,
        int $sourceGroup,
        int $targetGroup,
        float $confidence,
        string $text,
        array &$mappings,
        array &$seen,
    ): void {
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($matches as $match) {
            $sourceValue = $match[$sourceGroup];
            $targetValue = $match[$targetGroup];

            if (!$this->looksLikePhpCode($sourceValue)) {
                continue;
            }

            if (!$this->looksLikePhpCode($targetValue)) {
                continue;
            }

            $this->addMapping($sourceValue, $targetValue, $confidence, $mappings, $seen);
        }
    }

    /**
     * Create a MigrationMapping from raw values and add it to the results.
     *
     * @param list<MigrationMapping> $mappings
     * @param array<string, true>    $seen
     */
    private function addMapping(
        string $sourceValue,
        string $targetValue,
        float $confidence,
        array &$mappings,
        array &$seen,
    ): void {
        $source = CodeReference::fromPhpRole($sourceValue);
        $target = CodeReference::fromPhpRole($targetValue);

        if (!$source instanceof CodeReference) {
            return;
        }

        if (!$target instanceof CodeReference) {
            return;
        }

        $key = $source->className . '::' . ($source->member ?? '')
            . '->' . $target->className . '::' . ($target->member ?? '');

        if (isset($seen[$key])) {
            return;
        }

        $effectiveConfidence = $confidence * min(
            $source->resolutionConfidence,
            $target->resolutionConfidence,
        );

        $seen[$key] = true;
        $mappings[] = new MigrationMapping($source, $target, $effectiveConfidence);
    }

    /**
     * Determine whether a backtick-enclosed value looks like PHP code.
     *
     * Returns true for namespace separators, static/instance access, variables,
     * CamelCase class names, function calls, and CONSTANT_NAME identifiers.
     */
    private function looksLikePhpCode(string $value): bool
    {
        // Namespace separator
        if (str_contains($value, '\\')) {
            return true;
        }

        // Static access
        if (str_contains($value, '::')) {
            return true;
        }

        // Instance access
        if (str_contains($value, '->')) {
            return true;
        }

        // Variable or property
        if (str_starts_with($value, '$')) {
            return true;
        }

        // Function/method call
        if (str_ends_with($value, '()')) {
            return true;
        }

        // CamelCase class name
        if (preg_match('/^[A-Z][a-zA-Z0-9]+$/', $value) === 1) {
            return true;
        }

        // CONSTANT_NAME identifier (3+ chars, all uppercase with underscores/digits)
        return preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $value) === 1;
    }
}
