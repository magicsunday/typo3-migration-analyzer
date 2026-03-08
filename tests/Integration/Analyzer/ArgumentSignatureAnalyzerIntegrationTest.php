<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Integration\Analyzer;

use App\Analyzer\ArgumentSignatureAnalyzer;
use App\Dto\ArgumentCount;
use App\Parser\CodeBlockExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function preg_match;
use function str_starts_with;

use const PHP_INT_MAX;

/**
 * Integration tests verifying ArgumentSignatureAnalyzer against real TYPO3 classes and RST files.
 */
#[CoversClass(ArgumentSignatureAnalyzer::class)]
final class ArgumentSignatureAnalyzerIntegrationTest extends TestCase
{
    private ArgumentSignatureAnalyzer $analyzer;

    private CodeBlockExtractor $extractor;

    protected function setUp(): void
    {
        $this->analyzer  = new ArgumentSignatureAnalyzer();
        $this->extractor = new CodeBlockExtractor();
    }

    #[Test]
    public function reflectionDetectsGeneralUtilityMethods(): void
    {
        // GeneralUtility::makeInstance(string $className, mixed ...$constructorArguments)
        $result = $this->analyzer->analyzeWithReflection(
            GeneralUtility::class,
            'makeInstance',
        );

        self::assertInstanceOf(ArgumentCount::class, $result);
        self::assertSame(1, $result->numberOfMandatoryArguments);
        self::assertSame(PHP_INT_MAX, $result->maximumNumberOfArguments);
    }

    #[Test]
    public function codeBlockAnalysisFindsSignaturesInRealRstFiles(): void
    {
        $changelogDir = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/13.0/';

        $rstFiles = glob($changelogDir . '*.rst') ?: [];
        self::assertNotEmpty($rstFiles, 'No RST changelog files found');

        $processedFiles = 0;

        foreach ($rstFiles as $file) {
            $filename = basename($file);

            // Skip non-document files (e.g. Index.rst)
            if (
                !str_starts_with($filename, 'Deprecation-')
                && !str_starts_with($filename, 'Breaking-')
            ) {
                continue;
            }

            $content = file_get_contents($file);

            if ($content === false) {
                continue;
            }

            $codeBlocks = $this->extractor->extract($content);

            if ($codeBlocks === []) {
                continue;
            }

            // Extract a method name from the code blocks and try analysis
            foreach ($codeBlocks as $block) {
                if ($block->language !== 'php') {
                    continue;
                }

                // Try to find a method name in the code block
                if (preg_match('/function\s+(\w+)\s*\(/', $block->code, $matches) !== 1) {
                    continue;
                }

                // Run the analyzer — we just verify it does not throw
                $this->analyzer->analyzeCodeBlocks($codeBlocks, $matches[1]);
                ++$processedFiles;

                break;
            }
        }

        // Smoke test: verify the pipeline works end-to-end without errors.
        // We expect at least some files to have parseable method signatures.
        self::assertGreaterThanOrEqual(1, $processedFiles, 'Expected at least one RST file with a parseable method signature');
    }
}
