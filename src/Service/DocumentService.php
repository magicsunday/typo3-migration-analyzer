<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Service;

use App\Analyzer\MatcherCoverageAnalyzer;
use App\Dto\CoverageResult;
use App\Dto\MatcherEntry;
use App\Dto\RstDocument;
use App\Dto\VersionRange;
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function sprintf;

/**
 * Central service for loading and caching RST documents, matchers, and coverage data.
 */
final class DocumentService
{
    private VersionRange $versionRange;

    public function __construct(
        private readonly RstFileLocator $locator,
        private readonly MatcherConfigParser $matcherParser,
        private readonly MatcherCoverageAnalyzer $coverageAnalyzer,
        private readonly VersionRangeProvider $versionRangeProvider,
        private readonly CacheInterface $cache,
    ) {
        $this->versionRange = $this->versionRangeProvider->getDefaultRange();
    }

    /**
     * Returns the currently active version range.
     */
    public function getVersionRange(): VersionRange
    {
        return $this->versionRange;
    }

    /**
     * Sets the active version range for document loading.
     */
    public function setVersionRange(VersionRange $versionRange): void
    {
        $this->versionRange = $versionRange;
    }

    /**
     * Returns the version directories matching the current version range.
     *
     * @return string[]
     */
    public function getVersions(): array
    {
        return $this->versionRange->getVersionDirectories(
            $this->versionRangeProvider->getAvailableDirectories(),
        );
    }

    /**
     * Returns all parsed RST documents for the current version range.
     *
     * @return RstDocument[]
     */
    public function getDocuments(): array
    {
        $cacheKey = sprintf('rst_documents_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->locator->findAll($this->getVersions());
        });
    }

    /**
     * Returns all parsed matcher entries from the installed TYPO3 package.
     *
     * @return MatcherEntry[]
     */
    public function getMatchers(): array
    {
        return $this->cache->get('matcher_entries', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->matcherParser->parseFromInstalledPackage();
        });
    }

    /**
     * Returns the coverage analysis result for the current version range.
     */
    public function getCoverage(): CoverageResult
    {
        $cacheKey = sprintf('coverage_result_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): CoverageResult {
            $item->expiresAfter(3600);

            return $this->coverageAnalyzer->analyze($this->getDocuments(), $this->getMatchers());
        });
    }

    /**
     * Finds a single document by its filename.
     *
     * Searches the current version range first, then falls back to all available
     * version directories so detail pages work even when the version range changes.
     */
    public function findDocumentByFilename(string $filename): ?RstDocument
    {
        return $this->getDocumentIndex()[$filename]
            ?? $this->locator->findByFilename($filename, $this->versionRangeProvider->getAvailableDirectories());
    }

    /**
     * Returns an index of documents keyed by filename.
     *
     * @return array<string, RstDocument>
     */
    private function getDocumentIndex(): array
    {
        $cacheKey = sprintf('rst_documents_index_%s', $this->versionRange->getCacheKeySuffix());

        return $this->cache->get($cacheKey, function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            $index = [];

            foreach ($this->getDocuments() as $doc) {
                $index[$doc->filename] = $doc;
            }

            return $index;
        });
    }
}
