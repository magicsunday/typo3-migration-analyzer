<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Dto\CoverageResult;
use App\Dto\MatcherEntry;
use App\Dto\RstDocument;

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
        $covered = [];
        $uncovered = [];

        foreach ($documents as $document) {
            if (isset($referencedFiles[$document->filename])) {
                $covered[] = $document;
            } else {
                $uncovered[] = $document;
            }
        }

        // 4. Calculate percentage
        $totalDocuments = \count($documents);
        $coveragePercent = $totalDocuments > 0
            ? (float) (\count($covered) / $totalDocuments * 100.0)
            : 0.0;

        return new CoverageResult(
            covered: $covered,
            uncovered: $uncovered,
            coveragePercent: $coveragePercent,
            totalDocuments: $totalDocuments,
            totalMatchers: \count($matchers),
        );
    }
}
