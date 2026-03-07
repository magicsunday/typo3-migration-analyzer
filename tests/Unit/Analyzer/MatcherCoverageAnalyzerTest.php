<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\CoverageResult;
use App\Dto\DocumentType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MatcherCoverageAnalyzerTest extends TestCase
{
    private MatcherCoverageAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new MatcherCoverageAnalyzer();
    }

    #[Test]
    public function detectCoveredDocument(): void
    {
        $document = $this->createDocument('Deprecation-12345-SomeDeprecation.rst');

        $matcher = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\SomeClass->someMethod',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-12345-SomeDeprecation.rst'],
        );

        $result = $this->analyzer->analyze([$document], [$matcher]);

        self::assertInstanceOf(CoverageResult::class, $result);
        self::assertCount(1, $result->covered);
        self::assertCount(0, $result->uncovered);
        self::assertSame($document, $result->covered[0]);
        self::assertSame(100.0, $result->coveragePercent);
        self::assertSame(1, $result->totalDocuments);
        self::assertSame(1, $result->totalMatchers);
    }

    #[Test]
    public function detectUncoveredDocument(): void
    {
        $document = $this->createDocument('Deprecation-99999-NoCoverage.rst');

        $result = $this->analyzer->analyze([$document], []);

        self::assertInstanceOf(CoverageResult::class, $result);
        self::assertCount(0, $result->covered);
        self::assertCount(1, $result->uncovered);
        self::assertSame($document, $result->uncovered[0]);
        self::assertSame(0.0, $result->coveragePercent);
        self::assertSame(1, $result->totalDocuments);
        self::assertSame(0, $result->totalMatchers);
    }

    #[Test]
    public function calculateCoveragePercentage(): void
    {
        $coveredDoc = $this->createDocument('Deprecation-11111-Covered.rst');
        $uncoveredDoc = $this->createDocument('Deprecation-22222-Uncovered.rst');

        $matcher = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\SomeClass->coveredMethod',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-11111-Covered.rst'],
        );

        $result = $this->analyzer->analyze([$coveredDoc, $uncoveredDoc], [$matcher]);

        self::assertCount(1, $result->covered);
        self::assertCount(1, $result->uncovered);
        self::assertSame(50.0, $result->coveragePercent);
        self::assertSame(2, $result->totalDocuments);
        self::assertSame(1, $result->totalMatchers);
    }

    #[Test]
    public function analyzeEmptyDocumentsReturnsZeroCoverage(): void
    {
        $result = $this->analyzer->analyze([], []);

        self::assertSame(0.0, $result->coveragePercent);
        self::assertSame(0, $result->totalDocuments);
        self::assertSame(0, $result->totalMatchers);
        self::assertCount(0, $result->covered);
        self::assertCount(0, $result->uncovered);
    }

    #[Test]
    public function analyzeEmptyDocumentsWithMatchersReturnsZeroCoverage(): void
    {
        $matcher = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\SomeClass->someMethod',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-12345-SomeDeprecation.rst'],
        );

        $result = $this->analyzer->analyze([], [$matcher]);

        self::assertSame(0.0, $result->coveragePercent);
        self::assertSame(0, $result->totalDocuments);
        self::assertSame(1, $result->totalMatchers);
    }

    #[Test]
    public function multipleMatchersReferencingSameDocumentCountOnce(): void
    {
        $document = $this->createDocument('Deprecation-12345-Test.rst');

        $matcher1 = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-12345-Test.rst'],
        );

        $matcher2 = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->baz',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-12345-Test.rst'],
        );

        $result = $this->analyzer->analyze([$document], [$matcher1, $matcher2]);

        self::assertCount(1, $result->covered);
        self::assertCount(0, $result->uncovered);
        self::assertSame(100.0, $result->coveragePercent);
        self::assertSame(2, $result->totalMatchers);
    }

    #[Test]
    public function matcherReferencingNonExistentDocumentDoesNotCauseError(): void
    {
        $document = $this->createDocument('Deprecation-11111-Existing.rst');

        $matcher = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-99999-NonExistent.rst'],
        );

        $result = $this->analyzer->analyze([$document], [$matcher]);

        self::assertCount(0, $result->covered);
        self::assertCount(1, $result->uncovered);
        self::assertSame(0.0, $result->coveragePercent);
    }

    #[Test]
    public function coveragePercentageWithNonExactDivision(): void
    {
        $doc1 = $this->createDocument('Deprecation-11111-A.rst');
        $doc2 = $this->createDocument('Deprecation-22222-B.rst');
        $doc3 = $this->createDocument('Deprecation-33333-C.rst');

        $matcher = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Deprecation-11111-A.rst'],
        );

        $result = $this->analyzer->analyze([$doc1, $doc2, $doc3], [$matcher]);

        // 1/3 = 33.333...%
        self::assertEqualsWithDelta(33.333, $result->coveragePercent, 0.01);
    }

    private function createDocument(string $filename): RstDocument
    {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 0,
            title: 'Test document',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
        );
    }
}
