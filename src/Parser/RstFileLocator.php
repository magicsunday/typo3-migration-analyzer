<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Parser;

use App\Dto\RstDocument;
use Symfony\Component\Finder\Finder;

use function dirname;

final readonly class RstFileLocator
{
    public function __construct(
        private RstParser $parser,
    ) {
    }

    /**
     * @param string[] $versions e.g. ['12.0', '12.1', '13.0']
     *
     * @return RstDocument[]
     */
    public function findAll(array $versions): array
    {
        $changelogBaseDir = dirname(__DIR__, 2) . '/vendor/typo3/cms-core/Documentation/Changelog';
        $documents        = [];

        foreach ($versions as $version) {
            $versionDir = $changelogBaseDir . '/' . $version;

            if (!is_dir($versionDir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()
                ->in($versionDir)
                ->name('/^(Deprecation|Breaking)-\d+.*\.rst$/')
                ->sortByName();

            foreach ($finder as $file) {
                $documents[] = $this->parser->parseFile(
                    $file->getRealPath(),
                    $version,
                );
            }
        }

        return $documents;
    }
}
