<?php

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\ScanStatus;
use App\Parser\RstParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RstParserTest extends TestCase
{
    private RstParser $parser;

    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->parser = new RstParser();
        $this->fixturesDir = \dirname(__DIR__, 2).'/Fixtures/Rst';
    }

    #[Test]
    public function parseDeprecationDocument(): void
    {
        $filePath = $this->fixturesDir.'/Deprecation-99999-TestDeprecation.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        self::assertSame(DocumentType::Deprecation, $document->type);
        self::assertSame(99999, $document->issueId);
        self::assertSame('Deprecation: #99999 - Test method has been deprecated', $document->title);
        self::assertSame('13.0', $document->version);
        self::assertSame(ScanStatus::FullyScanned, $document->scanStatus);
        self::assertSame('Deprecation-99999-TestDeprecation.rst', $document->filename);

        // Verify sections are extracted
        self::assertStringContainsString('oldMethod()', $document->description);
        self::assertNotNull($document->impact);
        self::assertStringContainsString('E_USER_DEPRECATED', $document->impact);
        self::assertNotNull($document->migration);
        self::assertStringContainsString('NewUtility', $document->migration);
    }

    #[Test]
    public function extractCodeReferencesFromDeprecation(): void
    {
        $filePath = $this->fixturesDir.'/Deprecation-99999-TestDeprecation.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        // Should have deduplicated references:
        // - TYPO3\CMS\Core\Utility\TestUtility::oldMethod() (static method, appears 3x but deduplicated)
        // - TYPO3\CMS\Core\Utility\NewUtility::newMethod() (static method, appears 2x but deduplicated)
        // - TYPO3\CMS\Core\OldClass (class name)
        // Note: E_USER_DEPRECATED is not a FQCN, so it's excluded
        self::assertCount(3, $document->codeReferences);

        $classNames = array_map(
            static fn ($ref) => $ref->className,
            $document->codeReferences,
        );

        self::assertContains('TYPO3\CMS\Core\Utility\TestUtility', $classNames);
        self::assertContains('TYPO3\CMS\Core\Utility\NewUtility', $classNames);
        self::assertContains('TYPO3\CMS\Core\OldClass', $classNames);

        // Verify member names
        $testUtilityRef = null;
        $newUtilityRef = null;
        $oldClassRef = null;

        foreach ($document->codeReferences as $ref) {
            if ('TYPO3\CMS\Core\Utility\TestUtility' === $ref->className) {
                $testUtilityRef = $ref;
            }

            if ('TYPO3\CMS\Core\Utility\NewUtility' === $ref->className) {
                $newUtilityRef = $ref;
            }

            if ('TYPO3\CMS\Core\OldClass' === $ref->className) {
                $oldClassRef = $ref;
            }
        }

        self::assertNotNull($testUtilityRef);
        self::assertSame('oldMethod', $testUtilityRef->member);
        self::assertSame(CodeReferenceType::StaticMethod, $testUtilityRef->type);

        self::assertNotNull($newUtilityRef);
        self::assertSame('newMethod', $newUtilityRef->member);
        self::assertSame(CodeReferenceType::StaticMethod, $newUtilityRef->type);

        self::assertNotNull($oldClassRef);
        self::assertNull($oldClassRef->member);
        self::assertSame(CodeReferenceType::ClassName, $oldClassRef->type);
    }

    #[Test]
    public function parseBreakingDocument(): void
    {
        $filePath = $this->fixturesDir.'/Breaking-88888-TestBreaking.rst';

        $document = $this->parser->parseFile($filePath, '12.0');

        self::assertSame(DocumentType::Breaking, $document->type);
        self::assertSame(88888, $document->issueId);
        self::assertSame('Breaking: #88888 - Test class has been removed', $document->title);
        self::assertSame('12.0', $document->version);
        self::assertSame(ScanStatus::NotScanned, $document->scanStatus);
        self::assertSame('Breaking-88888-TestBreaking.rst', $document->filename);
    }

    #[Test]
    public function extractIndexTags(): void
    {
        $filePath = $this->fixturesDir.'/Deprecation-99999-TestDeprecation.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        // Should contain Backend, PHP-API, ext:core but NOT FullyScanned
        self::assertContains('Backend', $document->indexTags);
        self::assertContains('PHP-API', $document->indexTags);
        self::assertContains('ext:core', $document->indexTags);
        self::assertNotContains('FullyScanned', $document->indexTags);
        self::assertNotContains('PartiallyScanned', $document->indexTags);
        self::assertNotContains('NotScanned', $document->indexTags);
    }

    #[Test]
    public function extractPropertyReference(): void
    {
        $filePath = $this->fixturesDir.'/Breaking-88888-TestBreaking.rst';

        $document = $this->parser->parseFile($filePath, '12.0');

        $propertyRefs = array_filter(
            $document->codeReferences,
            static fn ($ref) => CodeReferenceType::Property === $ref->type,
        );

        self::assertCount(1, $propertyRefs);

        $propertyRef = array_values($propertyRefs)[0];
        self::assertSame('TYPO3\CMS\Core\DataHandling\DataHandler', $propertyRef->className);
        self::assertSame('recUpdateAccessCache', $propertyRef->member);
    }

    #[Test]
    public function parseFileThrowsOnUnreadableFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot read file/');

        $this->parser->parseFile('/nonexistent/path/file.rst', '13.0');
    }

    #[Test]
    public function parseFileThrowsOnUnknownDocumentType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown document type/');

        $filePath = $this->fixturesDir.'/Unknown-77777-TestUnknown.rst';
        $this->parser->parseFile($filePath, '13.0');
    }

    #[Test]
    public function parseFileThrowsOnMissingIssueId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No issue ID found/');

        $filePath = $this->fixturesDir.'/Deprecation-66666-NoIssueId.rst';
        $this->parser->parseFile($filePath, '13.0');
    }

    #[Test]
    public function parseFileThrowsOnMissingTitle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No title found/');

        $filePath = $this->fixturesDir.'/Deprecation-55555-NoTitle.rst';
        $this->parser->parseFile($filePath, '13.0');
    }

    #[Test]
    public function parseDocumentWithNoCodeReferencesReturnsEmptyArray(): void
    {
        $filePath = $this->fixturesDir.'/Deprecation-44444-NoCodeRefs.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        self::assertSame([], $document->codeReferences);
    }

    #[Test]
    public function extractSectionsWithDashUnderlines(): void
    {
        $filePath = $this->fixturesDir.'/Deprecation-33333-DashUnderlines.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        self::assertStringContainsString('deprecated', $document->description);
        self::assertNotNull($document->impact);
        self::assertStringContainsString('error', $document->impact);
    }

    #[Test]
    public function extractMultipleIndexDirectives(): void
    {
        $filePath = $this->fixturesDir.'/Deprecation-22222-MultiIndex.rst';

        $document = $this->parser->parseFile($filePath, '13.0');

        self::assertContains('Backend', $document->indexTags);
        self::assertContains('Frontend', $document->indexTags);
        self::assertSame(ScanStatus::PartiallyScanned, $document->scanStatus);
    }
}
