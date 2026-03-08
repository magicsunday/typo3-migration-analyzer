<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\VersionRange;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use App\Parser\RstParser;
use App\Service\DocumentService;
use App\Service\VersionRangeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function array_any;
use function str_starts_with;

#[CoversClass(DocumentService::class)]
final class DocumentServiceTest extends TestCase
{
    #[Test]
    public function getVersionsReturnsExpectedList(): void
    {
        $service  = $this->createServiceWithFixtures();
        $versions = $service->getVersions();

        self::assertNotEmpty($versions);

        // Default range is the latest migration path
        $range = $service->getVersionRange();
        self::assertGreaterThan(0, $range->sourceVersion);
    }

    #[Test]
    public function getVersionRangeReturnsDefaultRange(): void
    {
        $service = $this->createServiceWithFixtures();
        $range   = $service->getVersionRange();

        self::assertInstanceOf(VersionRange::class, $range);
    }

    #[Test]
    public function setVersionRangeChangesLoadedVersions(): void
    {
        $service = $this->createServiceWithFixtures();
        $service->setVersionRange(new VersionRange(11, 12));

        $versions = $service->getVersions();

        self::assertNotEmpty($versions);

        // Should contain 11.x versions
        $has11 = array_any($versions, static fn (string $v): bool => str_starts_with($v, '11.'));
        self::assertTrue($has11);
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

        self::assertGreaterThanOrEqual(0.0, $coverage->coveragePercent);
        self::assertLessThanOrEqual(100.0, $coverage->coveragePercent);
    }

    #[Test]
    public function findDocumentByFilenameReturnsMatchingDocument(): void
    {
        $service = $this->createServiceWithFixtures();

        // Use a document that we know exists in the real TYPO3 vendor data
        $documents = $service->getDocuments();

        if ($documents === []) {
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
            new VersionRangeProvider(),
            new ArrayAdapter(),
        );
    }
}
