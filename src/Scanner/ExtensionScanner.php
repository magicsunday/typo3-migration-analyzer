<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Scanner;

use App\Dto\ScanFileResult;
use App\Dto\ScanFinding;
use App\Dto\ScanResult;
use InvalidArgumentException;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use ReflectionClass;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Install\ExtensionScanner\Php\CodeStatistics;
use TYPO3\CMS\Install\ExtensionScanner\Php\GeneratorClassesResolver;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\AbstractCoreMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayDimensionMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayGlobalMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassNameMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstructorArgumentMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\FunctionCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\InterfaceMethodChangedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentUnusedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallArgumentValueMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyExistsStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyProtectedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyPublicMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ScalarStringMatcher;

use function dirname;
use function file_get_contents;
use function is_dir;
use function is_string;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Scans PHP files of a TYPO3 extension against the TYPO3 Extension Scanner matchers.
 */
final readonly class ExtensionScanner
{
    public function __construct(
        private CodeContextReader $contextReader = new CodeContextReader(),
    ) {
    }

    /**
     * Mapping of matcher class names to their configuration file names.
     *
     * @var array<class-string<AbstractCoreMatcher>, string>
     */
    private const array MATCHER_CONFIG = [
        ArrayDimensionMatcher::class               => 'ArrayDimensionMatcher.php',
        ArrayGlobalMatcher::class                  => 'ArrayGlobalMatcher.php',
        ClassConstantMatcher::class                => 'ClassConstantMatcher.php',
        ClassNameMatcher::class                    => 'ClassNameMatcher.php',
        ConstantMatcher::class                     => 'ConstantMatcher.php',
        ConstructorArgumentMatcher::class          => 'ConstructorArgumentMatcher.php',
        FunctionCallMatcher::class                 => 'FunctionCallMatcher.php',
        InterfaceMethodChangedMatcher::class       => 'InterfaceMethodChangedMatcher.php',
        MethodAnnotationMatcher::class             => 'MethodAnnotationMatcher.php',
        MethodArgumentDroppedMatcher::class        => 'MethodArgumentDroppedMatcher.php',
        MethodArgumentDroppedStaticMatcher::class  => 'MethodArgumentDroppedStaticMatcher.php',
        MethodArgumentRequiredMatcher::class       => 'MethodArgumentRequiredMatcher.php',
        MethodArgumentRequiredStaticMatcher::class => 'MethodArgumentRequiredStaticMatcher.php',
        MethodArgumentUnusedMatcher::class         => 'MethodArgumentUnusedMatcher.php',
        MethodCallMatcher::class                   => 'MethodCallMatcher.php',
        MethodCallArgumentValueMatcher::class      => 'MethodCallArgumentValueMatcher.php',
        MethodCallStaticMatcher::class             => 'MethodCallStaticMatcher.php',
        PropertyAnnotationMatcher::class           => 'PropertyAnnotationMatcher.php',
        PropertyExistsStaticMatcher::class         => 'PropertyExistsStaticMatcher.php',
        PropertyProtectedMatcher::class            => 'PropertyProtectedMatcher.php',
        PropertyPublicMatcher::class               => 'PropertyPublicMatcher.php',
        ScalarStringMatcher::class                 => 'ScalarStringMatcher.php',
    ];

    /**
     * Scan all PHP files of a TYPO3 extension and return aggregated results.
     */
    public function scan(string $extensionPath): ScanResult
    {
        if (!is_dir($extensionPath)) {
            throw new InvalidArgumentException(
                sprintf('Extension path "%s" does not exist or is not a directory.', $extensionPath),
            );
        }

        $finder = new Finder();
        $finder->files()
            ->in($extensionPath)
            ->name('*.php')
            ->sortByName();

        $fileResults = [];

        foreach ($finder as $file) {
            $scanFileResult = $this->scanFile($file->getRealPath(), $extensionPath);

            if ($scanFileResult instanceof ScanFileResult) {
                $fileResults[] = $scanFileResult;
            }
        }

        return new ScanResult(
            extensionPath: $extensionPath,
            fileResults: $fileResults,
        );
    }

    /**
     * Scan a single PHP file against all matchers.
     */
    private function scanFile(string $absoluteFilePath, string $basePath): ?ScanFileResult
    {
        $fileContent = file_get_contents($absoluteFilePath);

        if (!is_string($fileContent) || trim($fileContent) === '') {
            return null;
        }

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));

        $statements = $parser->parse($fileContent);

        if ($statements === null) {
            return null;
        }

        // First traverser pass: resolve names (use aliases to FQCN)
        $nameResolverTraverser = new NodeTraverser();
        $nameResolverTraverser->addVisitor(new NameResolver());

        $statements = $nameResolverTraverser->traverse($statements);

        // Second traverser pass: GeneratorClassesResolver + CodeStatistics + all matchers
        $matcherTraverser = new NodeTraverser();
        $matcherTraverser->addVisitor(new GeneratorClassesResolver());

        $codeStatistics = new CodeStatistics();
        $matcherTraverser->addVisitor($codeStatistics);

        $matchers = $this->createMatchers();

        foreach ($matchers as $matcher) {
            $matcherTraverser->addVisitor($matcher);
        }

        $matcherTraverser->traverse($statements);

        // Collect findings from all matchers
        $relativePath = str_replace($basePath . '/', '', $absoluteFilePath);

        $findings = [];

        foreach ($matchers as $matcher) {
            /** @var array<int, array{line: int, message: string, indicator: string, restFiles?: list<string>}> $matches */
            $matches = $matcher->getMatches();

            foreach ($matches as $match) {
                $findings[] = new ScanFinding(
                    line: $match['line'],
                    message: $match['message'],
                    indicator: $match['indicator'],
                    lineContent: $this->getLineFromFile($absoluteFilePath, $match['line']),
                    restFiles: $match['restFiles'] ?? [],
                    contextLines: $this->contextReader->readContext($absoluteFilePath, $match['line']),
                );
            }
        }

        return new ScanFileResult(
            filePath: $relativePath,
            findings: $findings,
            isFileIgnored: $codeStatistics->isFileIgnored(),
            effectiveCodeLines: $codeStatistics->getNumberOfEffectiveCodeLines(),
            ignoredLines: $codeStatistics->getNumberOfIgnoredLines(),
        );
    }

    /**
     * Load configuration files and instantiate all matcher NodeVisitors.
     *
     * @return list<AbstractCoreMatcher>
     */
    private function createMatchers(): array
    {
        $configDirectory = $this->getConfigDirectory();
        $matchers        = [];

        foreach (self::MATCHER_CONFIG as $matcherClass => $configFile) {
            $configFilePath = $configDirectory . '/' . $configFile;

            /** @var array<string, mixed> $configuration */
            $configuration = require $configFilePath;

            $matchers[] = new $matcherClass($configuration);
        }

        return $matchers;
    }

    /**
     * Determine the configuration directory path using reflection on ClassNameMatcher.
     */
    private function getConfigDirectory(): string
    {
        $reflection    = new ReflectionClass(ClassNameMatcher::class);
        $classFilePath = $reflection->getFileName();

        if ($classFilePath === false) {
            throw new RuntimeException('Cannot determine file path of ClassNameMatcher.');
        }

        // ClassNameMatcher lives in .../Classes/ExtensionScanner/Php/Matcher/ClassNameMatcher.php
        // Config files live in .../Configuration/ExtensionScanner/Php/
        return dirname($classFilePath, 5) . '/Configuration/ExtensionScanner/Php';
    }

    /**
     * Read a specific line from a file.
     */
    private function getLineFromFile(string $filePath, int $lineNumber): string
    {
        $file = new SplFileObject($filePath);
        $file->seek($lineNumber - 1);

        $line = $file->current();

        return is_string($line) ? trim($line) : '';
    }
}
