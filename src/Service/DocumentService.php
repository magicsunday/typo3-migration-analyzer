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
use App\Parser\MatcherConfigParser;
use App\Parser\RstFileLocator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class DocumentService
{
    private const VERSIONS = ['12.0', '12.1', '12.2', '12.3', '12.4', '12.4.x', '13.0', '13.1', '13.2', '13.3', '13.4', '13.4.x'];

    public function __construct(
        private RstFileLocator $locator,
        private MatcherConfigParser $matcherParser,
        private MatcherCoverageAnalyzer $coverageAnalyzer,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return string[]
     */
    public function getVersions(): array
    {
        return self::VERSIONS;
    }

    /**
     * @return RstDocument[]
     */
    public function getDocuments(): array
    {
        return $this->cache->get('rst_documents', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->locator->findAll(self::VERSIONS);
        });
    }

    /**
     * @return MatcherEntry[]
     */
    public function getMatchers(): array
    {
        return $this->cache->get('matcher_entries', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            return $this->matcherParser->parseFromInstalledPackage();
        });
    }

    public function getCoverage(): CoverageResult
    {
        return $this->cache->get('coverage_result', function (ItemInterface $item): CoverageResult {
            $item->expiresAfter(3600);

            return $this->coverageAnalyzer->analyze($this->getDocuments(), $this->getMatchers());
        });
    }

    public function findDocumentByFilename(string $filename): ?RstDocument
    {
        return $this->getDocumentIndex()[$filename] ?? null;
    }

    /**
     * @return array<string, RstDocument>
     */
    private function getDocumentIndex(): array
    {
        return $this->cache->get('rst_documents_index', function (ItemInterface $item): array {
            $item->expiresAfter(3600);

            $index = [];

            foreach ($this->getDocuments() as $doc) {
                $index[$doc->filename] = $doc;
            }

            return $index;
        });
    }
}
