<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Parser;

use App\Dto\CodeReference;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function basename;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function sprintf;
use function str_starts_with;
use function trim;

final readonly class RstParser
{
    /** Valid RST section underline characters: = - ` : . ' " ~ ^ _ * + # */
    private const string RST_UNDERLINE_PATTERN = '/^[=\-`:\.\'"~^_*+#]{3,}$/';

    private const array SCAN_STATUS_TAGS = [
        'FullyScanned',
        'PartiallyScanned',
        'NotScanned',
    ];

    public function __construct(
        private CodeBlockExtractor $codeBlockExtractor = new CodeBlockExtractor(),
    ) {
    }

    public function parseFile(string $filePath, string $version): RstDocument
    {
        $content = @file_get_contents($filePath);

        if ($content === false) {
            throw new RuntimeException(sprintf('Cannot read file: %s', $filePath));
        }

        $filename  = basename($filePath);
        $migration = $this->extractSection($content, 'Migration');

        return new RstDocument(
            type: $this->extractType($filename),
            issueId: $this->extractIssueId($content),
            title: $this->extractTitle($content),
            version: $version,
            description: $this->extractSection($content, 'Description') ?? '',
            impact: $this->extractSection($content, 'Impact'),
            migration: $migration,
            codeReferences: $this->extractCodeReferences($content),
            indexTags: $this->extractIndexTags($content),
            scanStatus: $this->extractScanStatus($content),
            filename: $filename,
            codeBlocks: $this->codeBlockExtractor->extract($migration),
        );
    }

    private function extractType(string $filename): DocumentType
    {
        if (str_starts_with($filename, 'Deprecation-')) {
            return DocumentType::Deprecation;
        }

        if (str_starts_with($filename, 'Breaking-')) {
            return DocumentType::Breaking;
        }

        if (str_starts_with($filename, 'Feature-')) {
            return DocumentType::Feature;
        }

        if (str_starts_with($filename, 'Important-')) {
            return DocumentType::Important;
        }

        throw new RuntimeException(sprintf('Unknown document type for filename: %s', $filename));
    }

    private function extractIssueId(string $content): int
    {
        if (preg_match('/:issue:`(\d+)`/', $content, $matches) === 1) {
            return (int) $matches[1];
        }

        throw new RuntimeException('No issue ID found in document');
    }

    private function extractTitle(string $content): string
    {
        if (preg_match('/^(?:Deprecation|Breaking|Feature|Important):\s+#\d+\s*-\s+(.+)$/m', $content, $matches) === 1) {
            return $matches[1];
        }

        throw new RuntimeException('No title found in document');
    }

    private function extractSection(string $content, string $sectionName): ?string
    {
        // Split content into lines
        $lines            = explode("\n", $content);
        $lineCount        = count($lines);
        $sectionStart     = null;
        $sectionUnderline = '';

        // Find the section header (case-insensitive match)
        for ($i = 0; $i < $lineCount; ++$i) {
            if (
                preg_match('/^' . preg_quote($sectionName, '/') . '$/i', trim($lines[$i])) === 1
                && isset($lines[$i + 1])
                && preg_match(self::RST_UNDERLINE_PATTERN, trim($lines[$i + 1])) === 1
            ) {
                // Remember the underline character to distinguish same-level from sub-sections
                $sectionUnderline = $lines[$i + 1][0];

                // Section content starts after the underline
                $sectionStart = $i + 2;

                break;
            }
        }

        if ($sectionStart === null) {
            return null;
        }

        // Collect lines until the next same-level section header or end of file
        $sectionLines = [];

        for ($i = $sectionStart; $i < $lineCount; ++$i) {
            // Stop at same-level section headers (same underline character); include subsections
            if (
                isset($lines[$i + 1])
                && preg_match(self::RST_UNDERLINE_PATTERN, trim($lines[$i + 1])) === 1
                && trim($lines[$i]) !== ''
                && $lines[$i + 1][0] === $sectionUnderline
            ) {
                break;
            }

            // Also stop at index directives
            if (preg_match('/^\.\.\s+index::/', trim($lines[$i])) === 1) {
                break;
            }

            $sectionLines[] = $lines[$i];
        }

        $sectionContent = implode("\n", $sectionLines);
        $sectionContent = trim($sectionContent);

        return $sectionContent === '' ? null : $sectionContent;
    }

    /**
     * @return list<CodeReference>
     */
    private function extractCodeReferences(string $content): array
    {
        if (preg_match_all('/:php:`([^`]+)`/', $content, $matches) === 0) {
            return [];
        }

        $seen       = [];
        $references = [];

        foreach ($matches[1] as $phpRoleValue) {
            $ref = CodeReference::fromPhpRole($phpRoleValue);

            if (!$ref instanceof CodeReference) {
                continue;
            }

            // Deduplicate by className + member
            $key = $ref->className . '::' . ($ref->member ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key]   = true;
            $references[] = $ref;
        }

        return $references;
    }

    /**
     * Collect all tags from every `.. index::` directive in the content.
     *
     * @return list<string>
     */
    private function collectAllIndexTags(string $content): array
    {
        if (preg_match_all('/\.\.\s+index::\s*(.+)$/m', $content, $matches) === 0) {
            return [];
        }

        $tags = [];

        foreach ($matches[1] as $tagLine) {
            foreach (array_map(trim(...), explode(',', $tagLine)) as $tag) {
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * @return list<string>
     */
    private function extractIndexTags(string $content): array
    {
        return array_values(
            array_filter(
                $this->collectAllIndexTags($content),
                static fn (string $tag): bool => !in_array($tag, self::SCAN_STATUS_TAGS, true),
            ),
        );
    }

    private function extractScanStatus(string $content): ScanStatus
    {
        foreach ($this->collectAllIndexTags($content) as $tag) {
            $status = ScanStatus::tryFrom(
                match ($tag) {
                    'FullyScanned'     => 'fully_scanned',
                    'PartiallyScanned' => 'partially_scanned',
                    'NotScanned'       => 'not_scanned',
                    default            => $tag,
                },
            );

            if ($status !== null) {
                return $status;
            }
        }

        return ScanStatus::NotScanned;
    }
}
