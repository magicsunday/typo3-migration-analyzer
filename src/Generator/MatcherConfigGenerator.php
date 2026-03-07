<?php

declare(strict_types=1);

namespace App\Generator;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;

final class MatcherConfigGenerator
{
    /**
     * Generate matcher entries from a document's code references.
     *
     * @return list<MatcherEntry>
     */
    public function generate(RstDocument $document): array
    {
        $entries = [];

        foreach ($document->codeReferences as $codeReference) {
            $matcherType = $this->resolveMatcherType($codeReference);
            $identifier = $this->buildIdentifier($codeReference);
            $additionalConfig = $this->buildAdditionalConfig($matcherType);

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

        $output .= "];\n";

        return $output;
    }

    private function resolveMatcherType(CodeReference $codeReference): MatcherType
    {
        return match ($codeReference->type) {
            CodeReferenceType::ClassName => MatcherType::ClassName,
            CodeReferenceType::InstanceMethod => MatcherType::MethodCall,
            CodeReferenceType::StaticMethod => MatcherType::MethodCallStatic,
            CodeReferenceType::Property => MatcherType::PropertyProtected,
            CodeReferenceType::ClassConstant => MatcherType::ClassConstant,
        };
    }

    private function buildIdentifier(CodeReference $codeReference): string
    {
        if (null === $codeReference->member) {
            return $codeReference->className;
        }

        return match ($codeReference->type) {
            CodeReferenceType::InstanceMethod,
            CodeReferenceType::Property => $codeReference->className.'->'.$codeReference->member,
            CodeReferenceType::StaticMethod,
            CodeReferenceType::ClassConstant => $codeReference->className.'::'.$codeReference->member,
            CodeReferenceType::ClassName => $codeReference->className,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAdditionalConfig(MatcherType $matcherType): array
    {
        if (MatcherType::MethodCall === $matcherType || MatcherType::MethodCallStatic === $matcherType) {
            return [
                'numberOfMandatoryArguments' => 0,
                'maximumNumberOfArguments' => 0,
            ];
        }

        return [];
    }

    private function renderEntry(MatcherEntry $entry): string
    {
        $escaped = $this->escapePhpString($entry->identifier);
        $output = "    '{$escaped}' => [\n";

        foreach ($entry->additionalConfig as $key => $value) {
            $output .= "        '{$key}' => {$value},\n";
        }

        $output .= "        'restFiles' => [\n";

        foreach ($entry->restFiles as $restFile) {
            $escapedFile = $this->escapePhpString($restFile);
            $output .= "            '{$escapedFile}',\n";
        }

        $output .= "        ],\n";
        $output .= "    ],\n";

        return $output;
    }

    private function escapePhpString(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }
}
