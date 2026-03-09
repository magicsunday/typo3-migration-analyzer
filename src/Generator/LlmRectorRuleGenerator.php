<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Generator;

use App\Dto\LlmAnalysisResult;
use App\Dto\LlmCodeMapping;
use App\Dto\LlmRectorAssessment;
use App\Dto\LlmRectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;

use function in_array;
use function mb_strtolower;
use function pathinfo;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function ucwords;

use const PATHINFO_FILENAME;

/**
 * Generates Rector rules from LLM analysis data.
 *
 * Produces config-type rules for simple renames (class_rename, method_rename,
 * constant_rename) and skeleton rule classes for complex patterns (hook_to_event,
 * argument_change, etc.) using code blocks from the RST document as fixtures.
 */
final readonly class LlmRectorRuleGenerator
{
    /**
     * Mapping types that produce config-based rector.php entries.
     */
    private const array CONFIG_TYPES = [
        'class_rename',
        'method_rename',
        'constant_rename',
    ];

    /**
     * Mapping types that produce skeleton rule classes.
     */
    private const array SKELETON_TYPES = [
        'hook_to_event',
        'argument_change',
        'method_removal',
        'class_removal',
        'tca_change',
        'behavior_change',
        'typoscript_change',
    ];

    public function __construct(
        private RectorConfigRenderer $configRenderer,
    ) {
    }

    /**
     * Generate Rector rules from LLM analysis result and RST document.
     *
     * @return list<LlmRectorRule>
     */
    public function generate(LlmAnalysisResult $result, RstDocument $document): array
    {
        if ($result->codeMappings === []) {
            return [];
        }

        $rules         = [];
        $configEntries = [];

        foreach ($result->codeMappings as $mapping) {
            if (in_array($mapping->type, self::CONFIG_TYPES, true) && $mapping->new !== null) {
                $entry = $this->toConfigEntry($mapping);

                if ($entry !== []) {
                    $configEntries[] = $entry;
                    $rules[]         = $this->createConfigRule($mapping, $result->filename);
                }
            } elseif (in_array($mapping->type, self::SKELETON_TYPES, true)) {
                $rules[] = $this->createSkeletonRule($mapping, $result, $document);
            }
        }

        return $rules;
    }

    /**
     * Render a combined rector.php config from config-type rules.
     *
     * @param list<LlmRectorRule> $rules
     */
    public function renderCombinedConfig(array $rules): string
    {
        $entries = [];

        foreach ($rules as $rule) {
            if ($rule->configPhp === null) {
                continue;
            }

            // Re-parse the config entry from the stored configPhp line
            $entry = $this->configPhpToEntry($rule);

            if ($entry !== []) {
                $entries[] = $entry;
            }
        }

        return $this->configRenderer->render($entries);
    }

    /**
     * Convert an LlmCodeMapping into a RectorConfigRenderer entry array.
     *
     * @return array<string, string>
     */
    private function toConfigEntry(LlmCodeMapping $mapping): array
    {
        return match ($mapping->type) {
            'class_rename' => [
                'type' => 'rename_class',
                'old'  => $mapping->old,
                'new'  => $mapping->new ?? '',
            ],
            'method_rename'   => $this->parseMethodRenameEntry($mapping),
            'constant_rename' => $this->parseConstantRenameEntry($mapping),
            default           => [],
        };
    }

    /**
     * Parse "ClassName::method" or "ClassName->method()" format for method renames.
     *
     * @return array<string, string>
     */
    private function parseMethodRenameEntry(LlmCodeMapping $mapping): array
    {
        [$oldClass, $oldMethod] = $this->parseClassMember($mapping->old);
        [$newClass, $newMethod] = $this->parseClassMember($mapping->new ?? '');

        if ($oldClass === '' || $oldMethod === '') {
            return [];
        }

        // If new class differs, treat as static method rename
        if ($newClass !== '' && $newClass !== $oldClass) {
            return [
                'type'      => 'rename_static_method',
                'oldClass'  => $oldClass,
                'oldMethod' => $oldMethod,
                'newClass'  => $newClass,
                'newMethod' => $newMethod,
            ];
        }

        return [
            'type'      => 'rename_method',
            'className' => $oldClass,
            'oldMethod' => $oldMethod,
            'newMethod' => $newMethod,
        ];
    }

    /**
     * Parse "ClassName::CONSTANT" format for constant renames.
     *
     * @return array<string, string>
     */
    private function parseConstantRenameEntry(LlmCodeMapping $mapping): array
    {
        [$oldClass, $oldConst] = $this->parseClassMember($mapping->old);
        [$newClass, $newConst] = $this->parseClassMember($mapping->new ?? '');

        if ($oldClass === '' || $oldConst === '') {
            return [];
        }

        return [
            'type'        => 'rename_class_constant',
            'oldClass'    => $oldClass,
            'oldConstant' => $oldConst,
            'newClass'    => $newClass !== '' ? $newClass : $oldClass,
            'newConstant' => $newConst,
        ];
    }

    /**
     * Parse "ClassName::member", "ClassName->member()", or "ClassName::member()" into [class, member].
     *
     * @return array{string, string}
     */
    private function parseClassMember(string $reference): array
    {
        // Strip trailing parentheses
        $reference = preg_replace('/\(\)$/', '', $reference) ?? $reference;

        // Try :: separator first
        if (str_contains($reference, '::')) {
            $parts = explode('::', $reference, 2);

            return [$parts[0], $parts[1]];
        }

        // Try -> separator
        if (str_contains($reference, '->')) {
            $parts = explode('->', $reference, 2);

            return [$parts[0], $parts[1]];
        }

        return [$reference, ''];
    }

    /**
     * Create a config-type LlmRectorRule.
     */
    private function createConfigRule(LlmCodeMapping $mapping, string $filename): LlmRectorRule
    {
        [$ruleType, $ruleClassName] = match ($mapping->type) {
            'class_rename'    => [RectorRuleType::RenameClass, 'RenameClassRector'],
            'method_rename'   => [RectorRuleType::RenameMethod, 'RenameMethodRector'],
            'constant_rename' => [RectorRuleType::RenameClassConstant, 'RenameClassConstFetchRector'],
            default           => [RectorRuleType::Skeleton, 'UnknownRector'],
        };

        return new LlmRectorRule(
            filename: $filename,
            type: $ruleType,
            ruleClassName: $ruleClassName,
            configPhp: sprintf('%s => %s', $mapping->old, $mapping->new ?? ''),
            rulePhp: null,
            testPhp: null,
            fixtureBeforePhp: null,
            fixtureAfterPhp: null,
        );
    }

    /**
     * Create a skeleton-type LlmRectorRule with generated PHP class and test fixtures.
     */
    private function createSkeletonRule(
        LlmCodeMapping $mapping,
        LlmAnalysisResult $result,
        RstDocument $document,
    ): LlmRectorRule {
        $className                = $this->resolveSkeletonClassName($result, $document);
        [$beforeCode, $afterCode] = $this->extractFixtures($document);

        return new LlmRectorRule(
            filename: $result->filename,
            type: RectorRuleType::Skeleton,
            ruleClassName: $className,
            configPhp: null,
            rulePhp: $this->renderSkeletonClass($className, $mapping, $document, $beforeCode, $afterCode),
            testPhp: $this->renderTestClass($className),
            fixtureBeforePhp: $beforeCode,
            fixtureAfterPhp: $afterCode,
        );
    }

    /**
     * Determine the class name for a skeleton rule.
     *
     * Prefers the LLM's suggested ruleType, falls back to generating from filename.
     */
    private function resolveSkeletonClassName(LlmAnalysisResult $result, RstDocument $document): string
    {
        $assessment = $result->rectorAssessment;

        if ($assessment instanceof LlmRectorAssessment && $assessment->ruleType !== null && $assessment->ruleType !== '') {
            return $assessment->ruleType;
        }

        return $this->generateClassNameFromFilename($document->filename);
    }

    /**
     * Generate a PascalCase Rector class name from an RST filename.
     */
    private function generateClassNameFromFilename(string $filename): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $name     = preg_replace('/^(?:Deprecation|Breaking|Feature|Important)-\d+-/', '', $basename) ?? $basename;
        $name     = str_replace(['-', '_', '.'], ' ', $name);
        $name     = ucwords($name);
        $name     = preg_replace('/[^A-Za-z0-9]/', '', $name) ?? $name;

        return $name . 'Rector';
    }

    /**
     * Extract Before/After code from RST document code blocks.
     *
     * @return array{string|null, string|null}
     */
    private function extractFixtures(RstDocument $document): array
    {
        $beforeLabels = ['before', 'vorher'];
        $afterLabels  = ['after', 'nachher'];

        $beforeCode = null;
        $afterCode  = null;

        foreach ($document->codeBlocks as $block) {
            if ($block->label === null) {
                continue;
            }

            $label = mb_strtolower($block->label);

            if (in_array($label, $beforeLabels, true)) {
                $beforeCode = $block->code;
            } elseif (in_array($label, $afterLabels, true)) {
                $afterCode = $block->code;
            }
        }

        return [$beforeCode, $afterCode];
    }

    /**
     * Render a skeleton Rector rule PHP class.
     */
    private function renderSkeletonClass(
        string $className,
        LlmCodeMapping $mapping,
        RstDocument $document,
        ?string $beforeCode,
        ?string $afterCode,
    ): string {
        $beforeSample = $beforeCode !== null
            ? sprintf("                    '%s'", $this->escapePhpString($beforeCode))
            : "                    '// TODO: Add before code sample'";
        $afterSample = $afterCode !== null
            ? sprintf("                    '%s'", $this->escapePhpString($afterCode))
            : "                    '// TODO: Add after code sample'";

        $output = "<?php\n\n";
        $output .= "declare(strict_types=1);\n\n";
        $output .= "namespace App\\Rector\\Generated;\n\n";
        $output .= "use PhpParser\\Node;\n";
        $output .= "use Rector\\Rector\\AbstractRector;\n";
        $output .= "use Symplify\\RuleDocGenerator\\ValueObject\\CodeSample\\CodeSample;\n";
        $output .= "use Symplify\\RuleDocGenerator\\ValueObject\\RuleDefinition;\n\n";
        $output .= sprintf("/**\n * %s\n *\n * @see %s\n */\n", $this->escapePhpString($document->title), $document->filename);
        $output .= sprintf("final class %s extends AbstractRector\n{\n", $className);

        // getRuleDefinition()
        $output .= "    public function getRuleDefinition(): RuleDefinition\n";
        $output .= "    {\n";
        $output .= "        return new RuleDefinition(\n";
        $output .= sprintf("            '%s',\n", $this->escapePhpString($document->title));
        $output .= "            [\n";
        $output .= "                new CodeSample(\n";
        $output .= $beforeSample . ",\n";
        $output .= $afterSample . ",\n";
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
        $output .= "        // TODO: Specify the node types to match\n";
        $output .= "        return [Node::class];\n";
        $output .= "    }\n\n";

        // refactor()
        $output .= "    public function refactor(Node \$node): ?Node\n";
        $output .= "    {\n";
        $output .= sprintf("        // TODO: Implement refactoring for %s => %s\n", $mapping->old, $mapping->new ?? 'removed');
        $output .= "\n";
        $output .= "        return null;\n";
        $output .= "    }\n";

        return $output . "}\n";
    }

    /**
     * Render a PHPUnit test class for a skeleton rule.
     */
    private function renderTestClass(string $className): string
    {
        $output = "<?php\n\n";
        $output .= "declare(strict_types=1);\n\n";
        $output .= "namespace App\\Tests\\Rector\\Generated;\n\n";
        $output .= "use App\\Rector\\Generated\\{$className};\n";
        $output .= "use Rector\\Testing\\PHPUnit\\AbstractRectorTestCase;\n\n";
        $output .= sprintf("final class %sTest extends AbstractRectorTestCase\n{\n", $className);
        $output .= "    public function provideConfigFilePath(): string\n";
        $output .= "    {\n";
        $output .= "        return __DIR__ . '/config/{$className}.php';\n";
        $output .= "    }\n\n";
        $output .= "    public function test(): void\n";
        $output .= "    {\n";
        $output .= "        // TODO: Add test with fixture file\n";
        $output .= "        // \$this->doTestFile(__DIR__ . '/Fixture/{$className}.php.inc');\n";
        $output .= "        self::assertTrue(true);\n";
        $output .= "    }\n";

        return $output . "}\n";
    }

    /**
     * Reconstruct a config entry from an LlmRectorRule for combined rendering.
     *
     * @return array<string, string>
     */
    private function configPhpToEntry(LlmRectorRule $rule): array
    {
        return match ($rule->type) {
            RectorRuleType::RenameClass => [
                'type' => 'rename_class',
                'old'  => $this->extractConfigPart($rule->configPhp ?? '', 0),
                'new'  => $this->extractConfigPart($rule->configPhp ?? '', 1),
            ],
            default => [],
        };
    }

    /**
     * Extract part from "old => new" config string.
     */
    private function extractConfigPart(string $configPhp, int $index): string
    {
        $parts = explode(' => ', $configPhp, 2);

        return $parts[$index] ?? '';
    }

    /**
     * Escape single quotes and backslashes for PHP string literals.
     */
    private function escapePhpString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
