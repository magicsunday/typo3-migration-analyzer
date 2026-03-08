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
use function class_exists;
use function count;
use function preg_match;
use function preg_quote;
use function str_contains;
use function strlen;
use function substr;
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
     *
     * Uses balanced parenthesis matching to correctly handle nested constructs
     * like array() defaults within parameter lists.
     */
    private function extractParameterList(string $code, string $escapedMethodName): ?string
    {
        // Find the opening parenthesis after the method name
        $pattern = '/function\s+' . $escapedMethodName . '\s*\(/s';

        if (preg_match($pattern, $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        // Position right after the opening parenthesis
        $start = $match[0][1] + strlen($match[0][0]);

        return $this->extractBalancedContent($code, $start);
    }

    /**
     * Extract content between balanced parentheses starting at the given position.
     *
     * Tracks nesting of (), [] and ignores delimiters inside string literals.
     */
    private function extractBalancedContent(string $code, int $start): ?string
    {
        $depth  = 1;
        $length = strlen($code);
        $i      = $start;

        while ($i < $length && $depth > 0) {
            $char = $code[$i];

            // Skip string literals
            if ($char === "'" || $char === '"') {
                $i = $this->skipStringLiteral($code, $i, $length);

                continue;
            }

            if ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            }

            ++$i;
        }

        if ($depth !== 0) {
            return null;
        }

        // $i points one past the closing delimiter
        return trim(substr($code, $start, $i - $start - 1));
    }

    /**
     * Skip past a string literal, handling escaped quotes.
     */
    private function skipStringLiteral(string $code, int $position, int $length): int
    {
        $quote = $code[$position];
        ++$position;

        while ($position < $length) {
            if ($code[$position] === '\\') {
                $position += 2;

                continue;
            }

            if ($code[$position] === $quote) {
                return $position + 1;
            }

            ++$position;
        }

        return $position;
    }

    /**
     * Parse a parameter list string into an ArgumentCount.
     *
     * Splits on commas at the top level only, respecting nested (), [] and strings.
     */
    private function parseParameterList(string $parameterList): ArgumentCount
    {
        if ($parameterList === '') {
            return new ArgumentCount(0, 0);
        }

        $parameters = $this->splitTopLevelCommas($parameterList);

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

    /**
     * Split a parameter list on commas, respecting nesting of (), [] and strings.
     *
     * @return list<string>
     */
    private function splitTopLevelCommas(string $parameterList): array
    {
        $parameters = [];
        $current    = '';
        $depth      = 0;
        $length     = strlen($parameterList);
        $i          = 0;

        while ($i < $length) {
            $char = $parameterList[$i];

            // Skip string literals
            if ($char === "'" || $char === '"') {
                $start = $i;
                $i     = $this->skipStringLiteral($parameterList, $i, $length);
                $current .= substr($parameterList, $start, $i - $start);

                continue;
            }

            if ($char === '(' || $char === '[') {
                ++$depth;
            } elseif ($char === ')' || $char === ']') {
                --$depth;
            }

            if ($char === ',' && $depth === 0) {
                $trimmed = trim($current);

                if ($trimmed !== '') {
                    $parameters[] = $trimmed;
                }

                $current = '';
                ++$i;

                continue;
            }

            $current .= $char;
            ++$i;
        }

        $trimmed = trim($current);

        if ($trimmed !== '') {
            $parameters[] = $trimmed;
        }

        return $parameters;
    }
}
