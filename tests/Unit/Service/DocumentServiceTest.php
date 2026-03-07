<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\CoverageResult;
use App\Dto\DocumentType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use App\Parser\RstParser;
use App\Service\DocumentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class DocumentServiceTest extends TestCase
{
    #[Test]
    public function getVersionsReturnsExpectedList(): void
    {
        $service = $this->createServiceWithFixtures();
        $versions = $service->getVersions();

        self::assertNotEmpty($versions);
        self::assertContains('13.0', $versions);
        self::assertContains('12.0', $versions);
    }

    #[Test]
    public function getDocumentsCachesResult(): void
    {
        $service = $this->createServiceWithFixtures();

        // Call twice — cached result should be equivalent
        $first  = $service->getDocuments();
        $second = $service->getDocuments();

        self::assertEquals($first, $second);
    }

    #[Test]
    public function getCoverageReturnsCorrectResult(): void
    {
        $service = $this->createServiceWithFixtures();

        $coverage = $service->getCoverage();

        self::assertInstanceOf(CoverageResult::class, $coverage);
        self::assertGreaterThanOrEqual(0.0, $coverage->coveragePercent);
        self::assertLessThanOrEqual(100.0, $coverage->coveragePercent);
    }

    #[Test]
    public function findDocumentByFilenameReturnsMatchingDocument(): void
    {
        $service = $this->createServiceWithFixtures();

        // Use a document that we know exists in the real TYPO3 vendor data
        $documents = $service->getDocuments();

        if ([] === $documents) {
            self::markTestSkipped('No documents found in vendor directory.');
        }

        $firstDoc = $documents[0];
        $found    = $service->findDocumentByFilename($firstDoc->filename);

        self::assertNotNull($found);
        self::assertSame($firstDoc->filename, $found->filename);
    }

    #[Test]
    public function findDocumentByFilenameReturnsNullWhenNotFound(): void
    {
        $service = $this->createServiceWithFixtures();

        $found = $service->findDocumentByFilename('NonExistent-99999-DoesNotExist.rst');

        self::assertNull($found);
    }

    #[Test]
    public function getCoverageCachesResult(): void
    {
        $service = $this->createServiceWithFixtures();

        $first  = $service->getCoverage();
        $second = $service->getCoverage();

        // Cached result should be equivalent
        self::assertEquals($first, $second);
    }

    private function createServiceWithFixtures(): DocumentService
    {
        return new DocumentService(
            new RstFileLocator(new RstParser()),
            new MatcherConfigParser(),
            new MatcherCoverageAnalyzer(),
            new ArrayAdapter(),
        );
    }
}
