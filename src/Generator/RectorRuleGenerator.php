<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Generator;

use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\MigrationMapping;
use App\Dto\RectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;

/**
 * Generates Rector rules from RST documents.
 *
 * Combines migration text mappings (via MigrationMappingExtractor) with code references
 * to produce config-based rules for simple renames and skeleton rules for complex changes.
 */
final class RectorRuleGenerator
{
    public function __construct(
        private readonly MigrationMappingExtractor $extractor,
    ) {
    }

    /**
     * Generate Rector rules from a document's migration section and code references.
     *
     * @return list<RectorRule>
     */
    public function generate(RstDocument $document): array
    {
        $mappings          = $this->extractor->extract($document->migration);
        $rules             = [];
        $handledSourceKeys = [];

        // 1. Generate config rules from detected mappings
        foreach ($mappings as $mapping) {
            $ruleType = $this->resolveConfigType($mapping);

            if ($ruleType === null) {
                continue;
            }

            $rules[] = new RectorRule(
                type: $ruleType,
                source: $mapping->source,
                target: $mapping->target,
                description: $document->title,
                rstFilename: $document->filename,
            );

            $handledSourceKeys[$this->buildRefKey($mapping->source)] = true;
        }

        // 2. Generate skeleton rules for code references without mappings
        foreach ($document->codeReferences as $ref) {
            $key = $this->buildRefKey($ref);

            if (isset($handledSourceKeys[$key])) {
                continue;
            }

            $rules[] = new RectorRule(
                type: RectorRuleType::Skeleton,
                source: $ref,
                target: null,
                description: $document->title,
                rstFilename: $document->filename,
            );
        }

        return $rules;
    }

    /**
     * Determine the config Rector rule type from a mapping, or null if types are incompatible.
     */
    private function resolveConfigType(MigrationMapping $mapping): ?RectorRuleType
    {
        return match (true) {
            $mapping->source->type === CodeReferenceType::ClassName
                && $mapping->target->type === CodeReferenceType::ClassName => RectorRuleType::RenameClass,

            $mapping->source->type === CodeReferenceType::InstanceMethod
                && $mapping->target->type === CodeReferenceType::InstanceMethod => RectorRuleType::RenameMethod,

            $mapping->source->type === CodeReferenceType::StaticMethod
                && $mapping->target->type === CodeReferenceType::StaticMethod => RectorRuleType::RenameStaticMethod,

            $mapping->source->type === CodeReferenceType::ClassConstant
                && $mapping->target->type === CodeReferenceType::ClassConstant => RectorRuleType::RenameClassConstant,

            default => null,
        };
    }

    private function buildRefKey(CodeReference $ref): string
    {
        return $ref->className . '::' . ($ref->member ?? '');
    }
}
