<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\AutomationGrade;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\ComplexityScore;
use App\Dto\LlmAnalysisResult;
use App\Dto\MigrationMapping;
use App\Dto\RstDocument;
use App\Repository\LlmResultRepository;

use function array_any;
use function ltrim;
use function mb_strlen;
use function mb_strtolower;
use function str_contains;
use function trim;

/**
 * Scores the complexity of migrating a deprecated/breaking RST document.
 *
 * When an LLM analysis result is available, it takes priority over the
 * heuristic scoring. Falls back to rule-based analysis otherwise.
 */
final readonly class ComplexityScorer
{
    /**
     * Keywords indicating a clear, actionable replacement exists in the migration text.
     */
    private const array REPLACEMENT_KEYWORDS = [
        'replace',
        'replacing',
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
        'has been migrated',
        'is now available',
        'now uses',
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
        private LlmResultRepository $repository,
    ) {
    }

    /**
     * Score the migration complexity of a document.
     *
     * Always computes the heuristic score. When an LLM analysis result is
     * available, it takes priority and the heuristic is attached for comparison.
     */
    public function score(RstDocument $document): ComplexityScore
    {
        $heuristic = $this->scoreByHeuristic($document);
        $llmResult = $this->repository->findLatest($document->filename);

        if ($llmResult instanceof LlmAnalysisResult) {
            return new ComplexityScore(
                score: $llmResult->score,
                reason: $llmResult->summary,
                automatable: $llmResult->automationGrade !== AutomationGrade::Manual,
                heuristicScore: $heuristic->score,
            );
        }

        return $heuristic;
    }

    /**
     * Score the document using rule-based heuristic analysis.
     */
    private function scoreByHeuristic(RstDocument $document): ComplexityScore
    {
        // Rule 1: Hook → Event migration (score 4)
        if ($this->isHookToEventMigration($document)) {
            return new ComplexityScore(4, 'Hook to event migration', false);
        }

        // Rule 2: TCA restructure (score 4)
        if ($this->isTcaChange($document)) {
            return new ComplexityScore(4, 'TCA structure change', false);
        }

        $mappings = $this->extractor->extract($document->migration, $document->description);

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

        $migrationText = trim($document->migration ?? '');

        // Rule 7: No or trivially short migration text (score 5)
        if ($migrationText === '' || mb_strlen($migrationText) <= 10) {
            return new ComplexityScore(5, 'No migration guidance', false);
        }

        // Rule 8: Explicitly states no replacement (score 5)
        if ($this->hasNoReplacementStatement($migrationText)) {
            return new ComplexityScore(5, 'No replacement available', false);
        }

        // Rule 9: Clear replacement keywords + code blocks (score 2)
        if ($this->hasClearReplacementInstructions($migrationText) && $document->codeBlocks !== []) {
            return new ComplexityScore(2, 'Replacement with code example', false);
        }

        // Rule 10: Clear replacement keywords without code blocks (score 3)
        if ($this->hasClearReplacementInstructions($migrationText)) {
            return new ComplexityScore(3, 'Replacement described in prose', false);
        }

        // Rule 11: Has code blocks but no replacement keywords (score 3)
        if ($document->codeBlocks !== []) {
            return new ComplexityScore(3, 'Code examples without clear mapping', false);
        }

        // Rule 12: Has migration text but nothing actionable (score 4)
        return new ComplexityScore(4, 'Migration guidance without clear replacement', false);
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
