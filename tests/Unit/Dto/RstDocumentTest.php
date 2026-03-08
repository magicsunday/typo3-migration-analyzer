<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RstDocumentTest extends TestCase
{
    #[Test]
    public function constructionWithAllParameters(): void
    {
        $codeReference = new CodeReference(
            className: GeneralUtility::class,
            member: 'fixPermissions',
            type: CodeReferenceType::StaticMethod,
        );

        $document = new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 98765,
            title: 'Deprecation: #98765 - Some feature deprecated',
            version: '13.0',
            description: 'Some feature has been deprecated.',
            impact: 'Using the old API will trigger a deprecation warning.',
            migration: 'Use the new API instead.',
            codeReferences: [$codeReference],
            indexTags: ['Backend', 'PHP-API'],
            scanStatus: ScanStatus::FullyScanned,
            filename: 'Deprecation-98765-SomeFeatureDeprecated.rst',
        );

        self::assertSame(DocumentType::Deprecation, $document->type);
        self::assertSame(98765, $document->issueId);
        self::assertSame('Deprecation: #98765 - Some feature deprecated', $document->title);
        self::assertSame('13.0', $document->version);
        self::assertSame('Some feature has been deprecated.', $document->description);
        self::assertSame('Using the old API will trigger a deprecation warning.', $document->impact);
        self::assertSame('Use the new API instead.', $document->migration);
        self::assertCount(1, $document->codeReferences);
        self::assertSame($codeReference, $document->codeReferences[0]);
        self::assertSame(['Backend', 'PHP-API'], $document->indexTags);
        self::assertSame(ScanStatus::FullyScanned, $document->scanStatus);
        self::assertSame('Deprecation-98765-SomeFeatureDeprecated.rst', $document->filename);
    }

    #[Test]
    public function constructionWithMinimalParameters(): void
    {
        $document = new RstDocument(
            type: DocumentType::Breaking,
            issueId: 12345,
            title: 'Breaking: #12345 - Something changed',
            version: '12.0',
            description: 'Something changed.',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Breaking-12345-SomethingChanged.rst',
        );

        self::assertSame(DocumentType::Breaking, $document->type);
        self::assertSame(12345, $document->issueId);
        self::assertNull($document->impact);
        self::assertNull($document->migration);
        self::assertCount(0, $document->codeReferences);
        self::assertCount(0, $document->indexTags);
        self::assertSame(ScanStatus::NotScanned, $document->scanStatus);
    }

    #[Test]
    public function constructionWithFeatureType(): void
    {
        $document = new RstDocument(
            type: DocumentType::Feature,
            issueId: 55555,
            title: 'Feature: #55555 - New feature',
            version: '13.4',
            description: 'A new feature.',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: ['Frontend'],
            scanStatus: ScanStatus::PartiallyScanned,
            filename: 'Feature-55555-NewFeature.rst',
        );

        self::assertSame(DocumentType::Feature, $document->type);
        self::assertSame(ScanStatus::PartiallyScanned, $document->scanStatus);
    }

    #[Test]
    public function constructionWithImportantType(): void
    {
        $document = new RstDocument(
            type: DocumentType::Important,
            issueId: 77777,
            title: 'Important: #77777 - Something important',
            version: '12.4',
            description: 'An important change.',
            impact: 'This is important.',
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Important-77777-SomethingImportant.rst',
        );

        self::assertSame(DocumentType::Important, $document->type);
    }

    #[Test]
    public function codeBlocksDefaultsToEmptyArray(): void
    {
        $document = new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 12345,
            title: 'Deprecation: #12345 - Test',
            version: '13.0',
            description: 'Description',
            impact: null,
            migration: null,
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-12345-Test.rst',
        );

        self::assertSame([], $document->codeBlocks);
    }
}
