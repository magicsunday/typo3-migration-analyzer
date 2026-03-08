<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Generator;

use App\Analyzer\ArgumentSignatureAnalyzer;
use App\Dto\ArgumentCount;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;

use function str_replace;
use function var_export;

final readonly class MatcherConfigGenerator
{
    public function __construct(
        private ArgumentSignatureAnalyzer $argumentSignatureAnalyzer = new ArgumentSignatureAnalyzer(),
    ) {
    }

    /**
     * Generate matcher entries from a document's code references.
     *
     * @return list<MatcherEntry>
     */
    public function generate(RstDocument $document): array
    {
        $entries = [];

        foreach ($document->codeReferences as $codeReference) {
            if ($codeReference->resolutionConfidence < 0.9) {
                continue;
            }

            $matcherType      = $this->resolveMatcherType($codeReference);
            $identifier       = $this->buildIdentifier($codeReference);
            $additionalConfig = $this->buildAdditionalConfig($matcherType, $codeReference, $document);

            $entries[] = new MatcherEntry(
                identifier: $identifier,
                matcherType: $matcherType,
                restFiles: [$document->filename],
                additionalConfig: $additionalConfig,
            );
        }

        return $entries;
    }

    /**
     * Render matcher entries as a PHP config array string.
     *
     * @param list<MatcherEntry> $entries
     */
    public function renderPhp(array $entries): string
    {
        $output = "<?php\n\nreturn [\n";

        foreach ($entries as $entry) {
            $output .= $this->renderEntry($entry);
        }

        return $output . "];\n";
    }

    private function resolveMatcherType(CodeReference $codeReference): MatcherType
    {
        return match ($codeReference->type) {
            CodeReferenceType::ClassName,
            CodeReferenceType::ShortClassName,
            CodeReferenceType::ConfigKey         => MatcherType::ClassName,
            CodeReferenceType::InstanceMethod    => MatcherType::MethodCall,
            CodeReferenceType::StaticMethod      => MatcherType::MethodCallStatic,
            CodeReferenceType::Property          => MatcherType::PropertyProtected,
            CodeReferenceType::ClassConstant     => MatcherType::ClassConstant,
            CodeReferenceType::UnqualifiedMethod => MatcherType::MethodCall,
        };
    }

    private function buildIdentifier(CodeReference $codeReference): string
    {
        if ($codeReference->member === null) {
            return $codeReference->className;
        }

        return match ($codeReference->type) {
            CodeReferenceType::InstanceMethod,
            CodeReferenceType::UnqualifiedMethod,
            CodeReferenceType::Property => $codeReference->className . '->' . $codeReference->member,
            CodeReferenceType::StaticMethod,
            CodeReferenceType::ClassConstant => $codeReference->className . '::' . $codeReference->member,
            CodeReferenceType::ClassName,
            CodeReferenceType::ShortClassName,
            CodeReferenceType::ConfigKey => $codeReference->className,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdditionalConfig(
        MatcherType $matcherType,
        CodeReference $codeReference,
        RstDocument $document,
    ): array {
        if ($matcherType === MatcherType::MethodCall || $matcherType === MatcherType::MethodCallStatic) {
            $argumentCount = $this->detectArguments($codeReference, $document);

            return $argumentCount->toConfigArray();
        }

        return [];
    }

    /**
     * Detect argument counts from code blocks or reflection, with fallback to zero.
     */
    private function detectArguments(CodeReference $codeReference, RstDocument $document): ArgumentCount
    {
        if ($codeReference->member !== null) {
            // Try code block analysis first
            $result = $this->argumentSignatureAnalyzer->analyzeCodeBlocks(
                $document->codeBlocks,
                $codeReference->member,
            );

            if ($result instanceof ArgumentCount) {
                return $result;
            }

            // Fall back to reflection on installed classes
            $result = $this->argumentSignatureAnalyzer->analyzeWithReflection(
                $codeReference->className,
                $codeReference->member,
            );

            if ($result instanceof ArgumentCount) {
                return $result;
            }
        }

        return new ArgumentCount(0, 0);
    }

    private function renderEntry(MatcherEntry $entry): string
    {
        $escaped = $this->escapePhpString($entry->identifier);
        $output  = "    '{$escaped}' => [\n";

        foreach ($entry->additionalConfig as $key => $value) {
            $rendered = var_export($value, true);
            $output .= "        '{$key}' => {$rendered},\n";
        }

        $output .= "        'restFiles' => [\n";

        foreach ($entry->restFiles as $restFile) {
            $escapedFile = $this->escapePhpString($restFile);
            $output .= "            '{$escapedFile}',\n";
        }

        $output .= "        ],\n";

        return $output . "    ],\n";
    }

    private function escapePhpString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
