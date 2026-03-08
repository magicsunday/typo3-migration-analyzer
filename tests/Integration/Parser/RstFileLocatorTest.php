<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Integration\Parser;

use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Parser\RstFileLocator;
use App\Parser\RstParser;
use App\Service\VersionRangeProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(RstFileLocator::class)]
final class RstFileLocatorTest extends TestCase
{
    private RstFileLocator $locator;

    protected function setUp(): void
    {
        $this->locator = new RstFileLocator(
            new RstParser(),
        );
    }

    #[Test]
    public function findAllDocumentsForVersionRange(): void
    {
        $provider  = new VersionRangeProvider();
        $range     = $provider->getDefaultRange();
        $versions  = $range->getVersionDirectories($provider->getAvailableDirectories());
        $documents = $this->locator->findAll($versions);

        self::assertNotEmpty($documents);

        // Verify documents from different versions are found
        $foundVersions = array_unique(
            array_map(
                static fn (RstDocument $doc): string => $doc->version,
                $documents,
            ),
        );

        // At least several versions should have documents
        self::assertGreaterThanOrEqual(6, count($foundVersions));
    }

    #[Test]
    public function filterByType(): void
    {
        $documents = $this->locator->findAll(['13.0']);

        $types = array_unique(
            array_map(
                static fn (RstDocument $doc): string => $doc->type->value,
                $documents,
            ),
        );

        self::assertContains(DocumentType::Deprecation->value, $types);
        self::assertContains(DocumentType::Breaking->value, $types);
    }

    #[Test]
    public function nonExistentVersionReturnsEmpty(): void
    {
        $documents = $this->locator->findAll(['99.99']);

        self::assertSame([], $documents);
    }
}
