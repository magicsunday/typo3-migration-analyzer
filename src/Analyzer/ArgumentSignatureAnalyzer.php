<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\ArgumentCount;
use App\Dto\CodeBlock;
use ReflectionException;
use ReflectionMethod;

use function array_filter;
use function array_map;
use function class_exists;
use function count;
use function explode;
use function preg_match;
use function preg_quote;
use function str_contains;
use function trim;

use const PHP_INT_MAX;

/**
 * Analyzes PHP code blocks to extract method signature argument counts.
 *
 * Parses function/method definitions to determine the number of mandatory and maximum
 * arguments from their parameter lists, including support for optional parameters with
 * defaults and variadic parameters.
 */
final class ArgumentSignatureAnalyzer
{
    /**
     * Analyze code blocks to find a method signature and extract argument counts.
     *
     * @param list<CodeBlock> $codeBlocks
     */
    public function analyzeCodeBlocks(array $codeBlocks, string $methodName): ?ArgumentCount
    {
        $phpBlocks = array_filter(
            $codeBlocks,
            static fn (CodeBlock $block): bool => $block->language === 'php',
        );

        $escapedName = preg_quote($methodName, '/');

        foreach ($phpBlocks as $block) {
            $parameterList = $this->extractParameterList($block->code, $escapedName);

            if ($parameterList === null) {
                continue;
            }

            return $this->parseParameterList($parameterList);
        }

        return null;
    }

    /**
     * Analyze argument counts using PHP reflection on an installed class.
     *
     * Falls back to reflection when code block parsing is not possible,
     * e.g. when the class is available in the vendor directory.
     */
    public function analyzeWithReflection(string $className, string $methodName): ?ArgumentCount
    {
        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new ReflectionMethod($className, $methodName);
        } catch (ReflectionException) {
            return null;
        }

        return new ArgumentCount(
            numberOfMandatoryArguments: $reflection->getNumberOfRequiredParameters(),
            maximumNumberOfArguments: $reflection->isVariadic() ? PHP_INT_MAX : $reflection->getNumberOfParameters(),
        );
    }

    /**
     * Extract the raw parameter list string from a method definition.
     */
    private function extractParameterList(string $code, string $escapedMethodName): ?string
    {
        // Match function/method definition with balanced parentheses
        $pattern = '/function\s+' . $escapedMethodName . '\s*\(([^)]*)\)/s';

        if (preg_match($pattern, $code, $match) !== 1) {
            return null;
        }

        return trim($match[1]);
    }

    /**
     * Parse a comma-separated parameter list into an ArgumentCount.
     */
    private function parseParameterList(string $parameterList): ArgumentCount
    {
        if ($parameterList === '') {
            return new ArgumentCount(0, 0);
        }

        $parameters = array_filter(
            array_map(trim(...), explode(',', $parameterList)),
            static fn (string $param): bool => $param !== '',
        );

        $mandatory  = 0;
        $total      = count($parameters);
        $isVariadic = false;

        foreach ($parameters as $parameter) {
            if (str_contains($parameter, '...')) {
                $isVariadic = true;

                continue;
            }

            if (!str_contains($parameter, '=')) {
                ++$mandatory;
            }
        }

        return new ArgumentCount(
            numberOfMandatoryArguments: $mandatory,
            maximumNumberOfArguments: $isVariadic ? PHP_INT_MAX : $total,
        );
    }
}
