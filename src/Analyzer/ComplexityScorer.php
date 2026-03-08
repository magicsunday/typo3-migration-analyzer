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
use App\Dto\CodeReferenceType;
use App\Dto\CodeBlock;
use App\Dto\ComplexityScore;
use App\Dto\MigrationMapping;
use App\Dto\RstDocument;

use function array_any;
use function array_filter;
use function ltrim;
use function mb_strtolower;
use function str_contains;
use function trim;

/**
 * Scores the complexity of migrating a deprecated/breaking RST document.
 *
 * Uses a rule-based approach analyzing migration text, code references,
 * index tags, and migration mappings to assign a score from 1 (trivial) to 5 (manual).
 */
final readonly class ComplexityScorer
{
    private const float HIGH_CONFIDENCE_THRESHOLD = 0.95;

    /**
     * Keywords indicating a clear, actionable replacement exists in the migration text.
     */
    private const array REPLACEMENT_KEYWORDS = [
        'replace',
        'rename',
        'migrate to',
        'switch to',
        'use instead',
        'use the',
        'can be removed',
        'not needed anymore',
        'should now use',
        'has been renamed',
        'has been replaced',
        'has been moved',
    ];

    /**
     * Keywords indicating no replacement exists — confirms Score 5.
     */
    private const array NO_REPLACEMENT_KEYWORDS = [
        'no direct replacement',
        'no replacement',
        'manual review',
        'has been removed without replacement',
        'without substitute',
        'must be reworked',
        'no migration path',
    ];

    public function __construct(
        private MigrationMappingExtractor $extractor,
    ) {
    }

    /**
     * Score the migration complexity of a document.
     */
    public function score(RstDocument $document): ComplexityScore
    {
        // Rule 1: Hook → Event migration (score 4)
        if ($this->isHookToEventMigration($document)) {
            return new ComplexityScore(4, 'Hook to event migration', false);
        }

        // Rule 2: TCA restructure (score 4)
        if ($this->isTcaChange($document)) {
            return new ComplexityScore(4, 'TCA structure change', false);
        }

        $mappings = $this->extractor->extract($document->migration);

        $migrationText = $document->migration ?? '';

        // Rule 3: Explicitly states no replacement (score 5)
        if ($migrationText !== '' && $this->hasNoReplacementStatement($migrationText)) {
            return new ComplexityScore(5, 'No replacement available', false);
        }

        // Rule 4: 1:1 high-confidence rename mapping exists for all references (score 1)
        if (
            $mappings !== []
            && $this->allReferencesHaveMappings($document, $mappings)
            && $this->allMappingsHaveHighConfidence($mappings)
        ) {
            return new ComplexityScore(1, 'Renamed with 1:1 mapping', true);
        }

        // Rule 5: Partial mappings — some refs have replacement (score 2)
        if ($mappings !== []) {
            return new ComplexityScore(2, 'Partial replacement available', true);
        }

        // Rule 6: Method/function refs with PHP code blocks suggest argument changes (score 3)
        if ($this->hasMethodRefsWithCodeBlocks($document)) {
            return new ComplexityScore(3, 'Argument signature changed', false);
        }

        // Rule 7: Has code references but no actionable mappings (score 3)
        if ($document->codeReferences !== []) {
            return new ComplexityScore(3, 'Code references without mapping', false);
        }

        // Rule 8: Clear replacement keywords + code blocks (score 2)
        if ($migrationText !== '' && $this->hasClearReplacementInstructions($migrationText) && $document->codeBlocks !== []) {
            return new ComplexityScore(2, 'Replacement with code example', false);
        }

        // Rule 9: Clear replacement keywords without code blocks (score 3)
        if ($migrationText !== '' && $this->hasClearReplacementInstructions($migrationText)) {
            return new ComplexityScore(3, 'Replacement described in prose', false);
        }

        // Rule 10: Has code blocks but no replacement keywords (score 3)
        if ($migrationText !== '' && $document->codeBlocks !== []) {
            return new ComplexityScore(3, 'Code examples without clear mapping', false);
        }

        // Rule 11: Has migration text but nothing actionable (score 4)
        if ($migrationText !== '' && trim($migrationText) !== '') {
            return new ComplexityScore(4, 'Migration guidance without clear replacement', false);
        }

        // Rule 12: No migration text at all (score 5)
        return new ComplexityScore(5, 'No migration guidance', false);
    }

    /**
     * Detect hook-to-event migration via index tags or title keywords.
     */
    private function isHookToEventMigration(RstDocument $document): bool
    {
        $hasHookTag = array_any(
            $document->indexTags,
            static fn (string $tag): bool => mb_strtolower($tag) === 'hook',
        );

        if ($hasHookTag) {
            return true;
        }

        $title = mb_strtolower($document->title);

        return str_contains($title, 'hook') && str_contains($title, 'removed');
    }

    /**
     * Detect TCA restructure via index tags or title keywords.
     */
    private function isTcaChange(RstDocument $document): bool
    {
        $hasTcaTag = array_any(
            $document->indexTags,
            static fn (string $tag): bool => mb_strtolower($tag) === 'tca',
        );

        if ($hasTcaTag) {
            return true;
        }

        return str_contains(mb_strtolower($document->title), 'tca');
    }

    /**
     * Check whether all code references in the document have corresponding mappings.
     *
     * @param list<MigrationMapping> $mappings
     */
    private function allReferencesHaveMappings(RstDocument $document, array $mappings): bool
    {
        if ($document->codeReferences === []) {
            // No code references but mappings exist — treat as simple rename
            return true;
        }

        $mappedSources = [];

        foreach ($mappings as $mapping) {
            $key = $this->normalizeKey(
                $mapping->source->className,
                $mapping->source->member,
            );
            $mappedSources[$key] = true;
        }

        foreach ($document->codeReferences as $ref) {
            $key = $this->normalizeKey($ref->className, $ref->member);

            if (!isset($mappedSources[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detect method or function references accompanied by code blocks.
     */
    private function hasMethodRefsWithCodeBlocks(RstDocument $document): bool
    {
        $phpCodeBlocks = array_filter(
            $document->codeBlocks,
            static fn (CodeBlock $block): bool => $block->language === 'php',
        );

        if ($phpCodeBlocks === []) {
            return false;
        }

        return array_any(
            $document->codeReferences,
            static fn (CodeReference $ref): bool => $ref->type === CodeReferenceType::InstanceMethod
                || $ref->type === CodeReferenceType::StaticMethod,
        );
    }

    /**
     * Check if the migration text contains clear replacement instructions.
     */
    private function hasClearReplacementInstructions(string $migrationText): bool
    {
        $lower = mb_strtolower($migrationText);

        return array_any(
            self::REPLACEMENT_KEYWORDS,
            static fn (string $keyword): bool => str_contains($lower, $keyword),
        );
    }

    /**
     * Check if the migration text explicitly states there is no replacement.
     */
    private function hasNoReplacementStatement(string $migrationText): bool
    {
        $lower = mb_strtolower($migrationText);

        return array_any(
            self::NO_REPLACEMENT_KEYWORDS,
            static fn (string $keyword): bool => str_contains($lower, $keyword),
        );
    }

    /**
     * @param list<MigrationMapping> $mappings
     */
    private function allMappingsHaveHighConfidence(array $mappings): bool
    {
        return !array_any(
            $mappings,
            static fn (MigrationMapping $mapping): bool => $mapping->confidence < self::HIGH_CONFIDENCE_THRESHOLD,
        );
    }

    /**
     * Build a normalized comparison key from class name and optional member.
     *
     * Strips leading "$" from member names so that property references
     * parsed from `:php:` roles (e.g. `Foo::$bar`) match code references
     * that store the member without the dollar sign.
     */
    private function normalizeKey(string $className, ?string $member): string
    {
        $normalizedMember = $member !== null ? ltrim($member, '$') : '';

        return $className . '::' . $normalizedMember;
    }
}
