<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CodeReferenceType;
use App\Dto\ComplexityScore;
use App\Dto\MigrationMapping;
use App\Dto\RstDocument;

use function array_any;
use function ltrim;
use function mb_strtolower;
use function str_contains;

/**
 * Scores the complexity of migrating a deprecated/breaking RST document.
 *
 * Uses a rule-based approach analyzing migration text, code references,
 * index tags, and migration mappings to assign a score from 1 (trivial) to 5 (manual).
 */
final readonly class ComplexityScorer
{
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

        // Rule 3: 1:1 rename mapping exists for all references (score 1)
        if ($mappings !== [] && $this->allReferencesHaveMappings($document, $mappings)) {
            return new ComplexityScore(1, 'Renamed with 1:1 mapping', true);
        }

        // Rule 4: Partial mappings — some refs have replacement (score 2)
        if ($mappings !== []) {
            return new ComplexityScore(2, 'Partial replacement available', true);
        }

        // Rule 5: Method/function refs with code blocks suggest argument changes (score 3)
        if ($this->hasMethodRefsWithCodeBlocks($document)) {
            return new ComplexityScore(3, 'Argument signature changed', false);
        }

        // Rule 6: Has code references but no mappings (score 3)
        if ($document->codeReferences !== []) {
            return new ComplexityScore(3, 'Code references without mapping', false);
        }

        // Rule 7: No migration text or no code references at all (score 5)
        return new ComplexityScore(5, 'Architecture change without clear replacement', false);
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
        if ($document->codeBlocks === []) {
            return false;
        }

        return array_any(
            $document->codeReferences,
            static fn ($ref): bool => $ref->type === CodeReferenceType::InstanceMethod
                || $ref->type === CodeReferenceType::StaticMethod,
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
