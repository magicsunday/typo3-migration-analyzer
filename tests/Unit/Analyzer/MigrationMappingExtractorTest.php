<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeReferenceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationMappingExtractorTest extends TestCase
{
    private MigrationMappingExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MigrationMappingExtractor();
    }

    #[Test]
    public function extractReplaceWithPattern(): void
    {
        $text = 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
        self::assertSame(CodeReferenceType::ClassName, $mappings[0]->source->type);
        self::assertSame(1.0, $mappings[0]->confidence);
    }

    #[Test]
    public function extractRenamedToPattern(): void
    {
        $text = 'The method :php:`\TYPO3\CMS\Core\Service::oldMethod()` has been renamed '
            . 'to :php:`\TYPO3\CMS\Core\Service::newMethod()`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\Service', $mappings[0]->source->className);
        self::assertSame('oldMethod', $mappings[0]->source->member);
        self::assertSame(CodeReferenceType::StaticMethod, $mappings[0]->source->type);
        self::assertSame('newMethod', $mappings[0]->target->member);
        self::assertSame(1.0, $mappings[0]->confidence);
    }

    #[Test]
    public function extractUseInsteadOfPattern(): void
    {
        $text = 'Use :php:`\TYPO3\CMS\Core\NewHelper` instead of :php:`\TYPO3\CMS\Core\OldHelper`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        // "Use NEW instead of OLD" — OLD is source, NEW is target
        self::assertSame('TYPO3\CMS\Core\OldHelper', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewHelper', $mappings[0]->target->className);
        self::assertSame(0.9, $mappings[0]->confidence);
    }

    #[Test]
    public function extractMigrateToPattern(): void
    {
        $text = 'Migrate from :php:`\TYPO3\CMS\Core\OldApi` to :php:`\TYPO3\CMS\Core\NewApi`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\OldApi', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewApi', $mappings[0]->target->className);
        self::assertSame(1.0, $mappings[0]->confidence);
    }

    #[Test]
    public function extractReturnsEmptyForNullText(): void
    {
        self::assertSame([], $this->extractor->extract(null));
    }

    #[Test]
    public function extractReturnsEmptyForEmptyText(): void
    {
        self::assertSame([], $this->extractor->extract(''));
    }

    #[Test]
    public function extractReturnsEmptyWhenNoPatternMatches(): void
    {
        $text = 'There is no direct replacement. Implement custom logic.';

        self::assertSame([], $this->extractor->extract($text));
    }

    #[Test]
    public function extractParsesNonFqcnReferences(): void
    {
        // Non-FQCN references are now parsed with lower confidence
        $text     = 'Replace :php:`oldFunction()` with :php:`newFunction()`.';
        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame(CodeReferenceType::UnqualifiedMethod, $mappings[0]->source->type);
        self::assertSame('oldFunction', $mappings[0]->source->member);
        self::assertSame(CodeReferenceType::UnqualifiedMethod, $mappings[0]->target->type);
        self::assertSame('newFunction', $mappings[0]->target->member);
        self::assertSame(0.5, $mappings[0]->source->resolutionConfidence);
    }

    #[Test]
    public function extractMultipleMappingsFromSameText(): void
    {
        $text = "Replace :php:`\\TYPO3\\CMS\\Core\\OldClass` with :php:`\\TYPO3\\CMS\\Core\\NewClass`.\n\n"
            . 'The method :php:`\TYPO3\CMS\Core\Service::oldMethod()` has been renamed '
            . 'to :php:`\TYPO3\CMS\Core\Service::newMethod()`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(2, $mappings);
    }

    #[Test]
    public function extractDeduplicatesSamePairFromDifferentPatterns(): void
    {
        // Both "Replace X with Y" and "Use Y instead of X" match the same pair
        $text = "Replace :php:`\\TYPO3\\CMS\\Core\\OldClass` with :php:`\\TYPO3\\CMS\\Core\\NewClass`.\n\n"
            . 'Use :php:`\TYPO3\CMS\Core\NewClass` instead of :php:`\TYPO3\CMS\Core\OldClass`.';

        $mappings = $this->extractor->extract($text);

        self::assertCount(1, $mappings);
        self::assertSame('TYPO3\CMS\Core\OldClass', $mappings[0]->source->className);
        self::assertSame('TYPO3\CMS\Core\NewClass', $mappings[0]->target->className);
    }
}
