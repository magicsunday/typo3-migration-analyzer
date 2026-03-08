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
use Rector\Config\RectorConfig;
use Rector\Renaming\Rector\ClassConstFetch\RenameClassConstFetchRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
use Rector\Renaming\Rector\StaticCall\RenameStaticMethodRector;
use Rector\Renaming\ValueObject\MethodCallRename;
use Rector\Renaming\ValueObject\RenameClassAndConstFetch;
use Rector\Renaming\ValueObject\RenameStaticMethod;

use function array_filter;
use function array_unique;
use function array_values;
use function pathinfo;
use function preg_replace;
use function sort;
use function sprintf;
use function str_replace;
use function ucwords;

use const PATHINFO_FILENAME;

/**
 * Generates Rector rules from RST documents.
 *
 * Combines migration text mappings (via MigrationMappingExtractor) with code references
 * to produce config-based rules for simple renames and skeleton rules for complex changes.
 */
final readonly class RectorRuleGenerator
{
    /**
     * Maps CodeReferenceType to PhpParser node class names for skeleton getNodeTypes().
     *
     * @var array<string, array{string, string}>
     */
    private const array NODE_TYPE_MAP = [
        'class_name'         => ['Node\Name\FullyQualified', 'FullyQualified'],
        'short_class_name'   => ['Node\Name\FullyQualified', 'FullyQualified'],
        'instance_method'    => ['Node\Expr\MethodCall', 'MethodCall'],
        'static_method'      => ['Node\Expr\StaticCall', 'StaticCall'],
        'unqualified_method' => ['Node\Expr\MethodCall', 'MethodCall'],
        'property'           => ['Node\Expr\PropertyFetch', 'PropertyFetch'],
        'class_constant'     => ['Node\Expr\ClassConstFetch', 'ClassConstFetch'],
        'config_key'         => ['Node\Name\FullyQualified', 'FullyQualified'],
    ];

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
        $mappings          = $this->extractor->extract($document->migration, $document->description);
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
            if ($ref->resolutionConfidence < 0.9) {
                continue;
            }

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

        $imports = [RectorConfig::class];
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

        return $output . "    ]);\n";
    }

    /**
     * Resolve Rector class, imports, and config entry for a single rule.
     *
     * @return array{string, list<string>, string}
     */
    private function resolveRectorConfig(RectorRule $rule): array
    {
        $target = $rule->target;

        if (!$target instanceof CodeReference) {
            return ['', [], ''];
        }

        return match ($rule->type) {
            RectorRuleType::RenameClass => [
                'RenameClassRector',
                [RenameClassRector::class],
                sprintf(
                    "'%s' => '%s'",
                    $this->escapePhpString($rule->source->className),
                    $this->escapePhpString($target->className),
                ),
            ],
            RectorRuleType::RenameMethod => [
                'RenameMethodRector',
                [
                    RenameMethodRector::class,
                    MethodCallRename::class,
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
                    RenameStaticMethodRector::class,
                    RenameStaticMethod::class,
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
                    RenameClassConstFetchRector::class,
                    RenameClassAndConstFetch::class,
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

    /**
     * Render a skeleton-type rule as a complete Rector Rule class file.
     */
    public function renderSkeleton(RectorRule $rule): string
    {
        $className = $this->generateClassName($rule);
        $nodeInfo  = self::NODE_TYPE_MAP[$rule->source->type->value];
        $sourceRef = $this->buildSourceComment($rule->source);

        $output = "<?php\n\n";
        $output .= "declare(strict_types=1);\n\n";
        $output .= "namespace App\\Rector\\Generated;\n\n";
        $output .= "use PhpParser\\Node;\n";
        $output .= sprintf("use PhpParser\\%s;\n", $nodeInfo[0]);
        $output .= "use Rector\\Rector\\AbstractRector;\n";
        $output .= "use Symplify\\RuleDocGenerator\\ValueObject\\CodeSample\\CodeSample;\n";
        $output .= "use Symplify\\RuleDocGenerator\\ValueObject\\RuleDefinition;\n\n";
        $output .= sprintf("/**\n * @see %s\n */\n", $rule->rstFilename);
        $output .= sprintf("final class %s extends AbstractRector\n{\n", $className);

        // getRuleDefinition()
        $output .= "    public function getRuleDefinition(): RuleDefinition\n";
        $output .= "    {\n";
        $output .= "        return new RuleDefinition(\n";
        $output .= sprintf("            '%s',\n", $this->escapePhpString($rule->description));
        $output .= "            [\n";
        $output .= "                new CodeSample(\n";
        $output .= "                    '// TODO: Add before code sample',\n";
        $output .= "                    '// TODO: Add after code sample',\n";
        $output .= "                ),\n";
        $output .= "            ],\n";
        $output .= "        );\n";
        $output .= "    }\n\n";

        // getNodeTypes()
        $output .= "    /**\n";
        $output .= "     * @return array<class-string<Node>>\n";
        $output .= "     */\n";
        $output .= "    public function getNodeTypes(): array\n";
        $output .= "    {\n";
        $output .= sprintf("        return [%s::class];\n", $nodeInfo[1]);
        $output .= "    }\n\n";

        // refactor()
        $output .= "    public function refactor(Node \$node): ?Node\n";
        $output .= "    {\n";
        $output .= "        // TODO: Implement refactoring logic\n";
        $output .= sprintf("        // Source: %s\n", $sourceRef);
        $output .= "\n";
        $output .= "        return null;\n";
        $output .= "    }\n";

        return $output . "}\n";
    }

    /**
     * Derive a Rector class name from the RST filename.
     *
     * Strips the document-type prefix, converts separator characters (hyphens, underscores, dots)
     * to PascalCase and removes any remaining non-alphanumeric characters.
     *
     * Example: "Breaking-94243-SendUserSessionCookiesAsHash-signedJWT.rst" -> "SendUserSessionCookiesAsHashSignedJWTRector"
     */
    public function generateClassName(RectorRule $rule): string
    {
        $basename = pathinfo($rule->rstFilename, PATHINFO_FILENAME);
        $name     = preg_replace('/^(?:Deprecation|Breaking|Feature|Important)-\d+-/', '', $basename) ?? $basename;
        $name     = str_replace(['-', '_', '.'], ' ', $name);
        $name     = ucwords($name);
        $name     = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? $name;

        return $name . 'Rector';
    }

    /**
     * Build a human-readable source reference comment.
     */
    private function buildSourceComment(CodeReference $ref): string
    {
        if ($ref->member === null) {
            return $ref->className;
        }

        $separator = match ($ref->type) {
            CodeReferenceType::InstanceMethod,
            CodeReferenceType::Property => '->',
            CodeReferenceType::StaticMethod,
            CodeReferenceType::ClassConstant => '::',
            default                          => '::',
        };

        $suffix = match ($ref->type) {
            CodeReferenceType::InstanceMethod,
            CodeReferenceType::StaticMethod => '()',
            default                         => '',
        };

        return $ref->className . $separator . $ref->member . $suffix;
    }

    private function escapePhpString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    private function buildRefKey(CodeReference $ref): string
    {
        return $ref->className . '::' . ($ref->member ?? '');
    }
}
