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

use function array_filter;
use function array_unique;
use function array_values;
use function sort;
use function sprintf;
use function str_replace;

/**
 * Generates Rector rules from RST documents.
 *
 * Combines migration text mappings (via MigrationMappingExtractor) with code references
 * to produce config-based rules for simple renames and skeleton rules for complex changes.
 */
final readonly class RectorRuleGenerator
{
    public function __construct(
        private MigrationMappingExtractor $extractor,
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

            if (!$ruleType instanceof RectorRuleType) {
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

    /**
     * Render config-type rules as a rector.php configuration file.
     *
     * @param list<RectorRule> $rules
     */
    public function renderConfig(array $rules): string
    {
        $configRules = array_values(array_filter(
            $rules,
            static fn (RectorRule $r): bool => $r->isConfig(),
        ));

        if ($configRules === []) {
            return '';
        }

        $imports = ['Rector\Config\RectorConfig'];
        $groups  = [];

        foreach ($configRules as $rule) {
            [$rectorShortName, $ruleImports, $entry] = $this->resolveRectorConfig($rule);

            foreach ($ruleImports as $import) {
                $imports[] = $import;
            }

            $groups[$rectorShortName][] = $entry;
        }

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = "<?php\n\ndeclare(strict_types=1);\n\n";

        foreach ($imports as $import) {
            $output .= sprintf("use %s;\n", $import);
        }

        $output .= "\nreturn RectorConfig::configure()\n";
        $output .= "    ->withConfiguredRules([\n";

        foreach ($groups as $shortName => $entries) {
            $output .= sprintf("        %s::class => [\n", $shortName);

            foreach ($entries as $entry) {
                $output .= sprintf("            %s,\n", $entry);
            }

            $output .= "        ],\n";
        }

        $output .= "    ]);\n";

        return $output;
    }

    /**
     * Resolve Rector class, imports, and config entry for a single rule.
     *
     * @return array{string, list<string>, string}
     */
    private function resolveRectorConfig(RectorRule $rule): array
    {
        $target = $rule->target;

        if ($target === null) {
            return ['', [], ''];
        }

        return match ($rule->type) {
            RectorRuleType::RenameClass => [
                'RenameClassRector',
                ['Rector\Renaming\Rector\Name\RenameClassRector'],
                sprintf(
                    "'%s' => '%s'",
                    $this->escapePhpString($rule->source->className),
                    $this->escapePhpString($target->className),
                ),
            ],
            RectorRuleType::RenameMethod => [
                'RenameMethodRector',
                [
                    'Rector\Renaming\Rector\MethodCall\RenameMethodRector',
                    'Rector\Renaming\ValueObject\MethodCallRename',
                ],
                sprintf(
                    "new MethodCallRename('%s', '%s', '%s')",
                    $this->escapePhpString($rule->source->className),
                    $this->escapePhpString($rule->source->member ?? ''),
                    $this->escapePhpString($target->member ?? ''),
                ),
            ],
            RectorRuleType::RenameStaticMethod => [
                'RenameStaticMethodRector',
                [
                    'Rector\Renaming\Rector\StaticCall\RenameStaticMethodRector',
                    'Rector\Renaming\ValueObject\RenameStaticMethod',
                ],
                sprintf(
                    "new RenameStaticMethod('%s', '%s', '%s', '%s')",
                    $this->escapePhpString($rule->source->className),
                    $this->escapePhpString($rule->source->member ?? ''),
                    $this->escapePhpString($target->className),
                    $this->escapePhpString($target->member ?? ''),
                ),
            ],
            RectorRuleType::RenameClassConstant => [
                'RenameClassConstFetchRector',
                [
                    'Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector',
                    'Rector\Renaming\ValueObject\RenameClassAndConstFetch',
                ],
                sprintf(
                    "new RenameClassAndConstFetch('%s', '%s', '%s', '%s')",
                    $this->escapePhpString($rule->source->className),
                    $this->escapePhpString($rule->source->member ?? ''),
                    $this->escapePhpString($target->className),
                    $this->escapePhpString($target->member ?? ''),
                ),
            ],
            default => ['', [], ''],
        };
    }

    private function escapePhpString(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    private function buildRefKey(CodeReference $ref): string
    {
        return $ref->className . '::' . ($ref->member ?? '');
    }
}
