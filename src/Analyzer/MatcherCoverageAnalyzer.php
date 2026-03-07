<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CoverageBreakdown;
use App\Dto\CoverageResult;
use App\Dto\MatcherEntry;
use App\Dto\RstDocument;

use function count;
use function ksort;
use function str_replace;
use function ucfirst;

final class MatcherCoverageAnalyzer
{
    /**
     * Compare RST documents against matcher entries to calculate coverage.
     *
     * @param RstDocument[]  $documents
     * @param MatcherEntry[] $matchers
     */
    public function analyze(array $documents, array $matchers): CoverageResult
    {
        // 1. Build a set of RST filenames referenced by matchers
        $referencedFiles = [];

        foreach ($matchers as $matcher) {
            foreach ($matcher->restFiles as $restFile) {
                $referencedFiles[$restFile] = true;
            }
        }

        // 2. For each document, check if its filename is in the set
        // 3. Split into covered/uncovered
        $covered   = [];
        $uncovered = [];

        foreach ($documents as $document) {
            if (isset($referencedFiles[$document->filename])) {
                $covered[] = $document;
            } else {
                $uncovered[] = $document;
            }
        }

        // 4. Calculate percentage
        $totalDocuments  = count($documents);
        $coveragePercent = $totalDocuments > 0
            ? count($covered) / $totalDocuments * 100.0
            : 0.0;

        // 5. Build breakdowns by version and type
        $byVersion    = $this->buildBreakdown($documents, $referencedFiles, 'version');
        $byType       = $this->buildBreakdown($documents, $referencedFiles, 'type');
        $byScanStatus = $this->buildBreakdown($documents, $referencedFiles, 'scanStatus');

        return new CoverageResult(
            covered: $covered,
            uncovered: $uncovered,
            coveragePercent: $coveragePercent,
            totalDocuments: $totalDocuments,
            totalMatchers: count($matchers),
            byVersion: $byVersion,
            byType: $byType,
            byScanStatus: $byScanStatus,
        );
    }

    /**
     * Build coverage breakdown by a document property (version, type, or scanStatus).
     *
     * @param RstDocument[]       $documents
     * @param array<string, true> $referencedFiles
     *
     * @return list<CoverageBreakdown>
     */
    private function buildBreakdown(array $documents, array $referencedFiles, string $property): array
    {
        /** @var array<string, array{total: int, covered: int}> $groups */
        $groups = [];

        foreach ($documents as $document) {
            $key = match ($property) {
                'type'       => ucfirst($document->type->value),
                'scanStatus' => ucfirst(str_replace('_', ' ', $document->scanStatus->value)),
                default      => $document->version,
            };

            if (!isset($groups[$key])) {
                $groups[$key] = ['total' => 0, 'covered' => 0];
            }

            ++$groups[$key]['total'];

            if (isset($referencedFiles[$document->filename])) {
                ++$groups[$key]['covered'];
            }
        }

        ksort($groups);

        $breakdowns = [];

        foreach ($groups as $label => $counts) {
            $percent = $counts['total'] > 0
                ? $counts['covered'] / $counts['total'] * 100.0
                : 0.0;

            $breakdowns[] = new CoverageBreakdown(
                label: $label,
                total: $counts['total'],
                covered: $counts['covered'],
                percent: $percent,
            );
        }

        return $breakdowns;
    }
}
