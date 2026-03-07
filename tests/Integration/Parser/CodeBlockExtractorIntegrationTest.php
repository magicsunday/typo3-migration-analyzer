<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Integration\Parser;

use App\Parser\RstParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function basename;
use function count;
use function dirname;
use function glob;
use function is_string;
use function str_starts_with;

/**
 * Integration tests verifying CodeBlockExtractor against real TYPO3 RST files.
 */
final class CodeBlockExtractorIntegrationTest extends TestCase
{
    #[Test]
    public function extractFromRstFileWithExplicitBeforeAfterSections(): void
    {
        // Deprecation-105213-TCASubTypes.rst has explicit Before/After subsections
        $filePath = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/13.4/Deprecation-105213-TCASubTypes.rst';

        $parser   = new RstParser();
        $document = $parser->parseFile($filePath, '13.4');

        self::assertNotNull($document->migration);
        self::assertNotEmpty($document->codeBlocks);

        $labels = [];

        foreach ($document->codeBlocks as $block) {
            if (is_string($block->label)) {
                $labels[] = $block->label;
            }
        }

        self::assertContains('Before', $labels);
        self::assertContains('After', $labels);
    }

    #[Test]
    public function extractFromRstFileWithMultipleSequentialBlocks(): void
    {
        // Breaking-96044 has multiple sequential code blocks without Before/After labels
        $filePath = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/12.0/Breaking-96044-HardenMethodSignatureOfLogicalAndAndLogicalOr.rst';

        $parser   = new RstParser();
        $document = $parser->parseFile($filePath, '12.0');

        self::assertNotNull($document->migration);
        self::assertGreaterThanOrEqual(2, count($document->codeBlocks));

        foreach ($document->codeBlocks as $block) {
            self::assertSame('php', $block->language);
            self::assertNotEmpty($block->code);
        }
    }

    #[Test]
    public function allParsedDocumentsHaveValidCodeBlocks(): void
    {
        $changelogDir = dirname(__DIR__, 3)
            . '/vendor/typo3/cms-core/Documentation/Changelog/13.0/';

        $files = glob($changelogDir . '*.rst') ?: [];
        self::assertNotEmpty($files);

        $parser = new RstParser();

        foreach ($files as $file) {
            $filename = basename($file);

            // Skip non-document files (e.g. Index.rst)
            if (
                !str_starts_with($filename, 'Deprecation-')
                && !str_starts_with($filename, 'Breaking-')
                && !str_starts_with($filename, 'Feature-')
                && !str_starts_with($filename, 'Important-')
            ) {
                continue;
            }

            $document = $parser->parseFile($file, '13.0');

            foreach ($document->codeBlocks as $block) {
                self::assertNotEmpty(
                    $block->language,
                    'Code block in ' . $document->filename . ' has empty language',
                );
                self::assertNotEmpty(
                    $block->code,
                    'Code block in ' . $document->filename . ' has empty code',
                );
            }
        }
    }
}
