<?php

declare(strict_types=1);

namespace App\Parser;

use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use RuntimeException;

use function array_diff_key;
use function is_array;
use function is_dir;
use function is_file;

final class MatcherConfigParser
{
    /**
     * Parse all matcher configuration files from the installed TYPO3 cms-install package.
     *
     * @return list<MatcherEntry>
     */
    public function parseFromInstalledPackage(): array
    {
        $configDir = $this->findConfigDirectory();

        if (null === $configDir) {
            throw new RuntimeException('TYPO3 Extension Scanner config directory not found. Is typo3/cms-install installed?');
        }

        $entries = [];

        foreach (MatcherType::cases() as $matcherType) {
            $filePath = $configDir.'/'.$matcherType->value.'.php';

            if (!is_file($filePath)) {
                continue;
            }

            $config = include $filePath;

            if (!is_array($config)) {
                continue;
            }

            /** @var array<string, array<string, mixed>> $config */
            foreach ($config as $identifier => $entry) {
                /** @var list<string> $restFiles */
                $restFiles = $entry['restFiles'] ?? [];
                $additionalConfig = array_diff_key($entry, ['restFiles' => true]);

                $entries[] = new MatcherEntry(
                    identifier: $identifier,
                    matcherType: $matcherType,
                    restFiles: $restFiles,
                    additionalConfig: $additionalConfig,
                );
            }
        }

        return $entries;
    }

    /**
     * Group matcher entries by their referenced RST filenames.
     *
     * @param list<MatcherEntry> $entries
     *
     * @return array<string, list<MatcherEntry>>
     */
    public function groupByRestFile(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            foreach ($entry->restFiles as $rstFile) {
                $grouped[$rstFile][] = $entry;
            }
        }

        return $grouped;
    }

    /**
     * Locate the Extension Scanner PHP config directory from the installed package.
     */
    public function findConfigDirectory(): ?string
    {
        $projectRoot = \dirname(__DIR__, 2);
        $configDir = $projectRoot.'/vendor/typo3/cms-install/Configuration/ExtensionScanner/Php';

        if (is_dir($configDir)) {
            return $configDir;
        }

        return null;
    }
}
