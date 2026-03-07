<?php

declare(strict_types=1);

namespace App\Tests\Unit\Parser;

use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Parser\MatcherConfigParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MatcherConfigParserTest extends TestCase
{
    private MatcherConfigParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MatcherConfigParser();
    }

    #[Test]
    public function parseAllMatcherConfigs(): void
    {
        $entries = $this->parser->parseFromInstalledPackage();

        self::assertNotEmpty($entries);
        self::assertContainsOnlyInstancesOf(MatcherEntry::class, $entries);
    }

    #[Test]
    public function entriesContainRestFileReferences(): void
    {
        $entries = $this->parser->parseFromInstalledPackage();

        foreach ($entries as $entry) {
            self::assertNotEmpty(
                $entry->restFiles,
                sprintf('Entry "%s" (type: %s) has no restFiles', $entry->identifier, $entry->matcherType->value),
            );
        }
    }

    #[Test]
    public function entriesHaveCorrectMatcherTypes(): void
    {
        $entries = $this->parser->parseFromInstalledPackage();

        $types = [];

        foreach ($entries as $entry) {
            $types[$entry->matcherType->value] = $entry->matcherType;
        }

        self::assertArrayHasKey(MatcherType::MethodCall->value, $types);
        self::assertArrayHasKey(MatcherType::ClassName->value, $types);
    }

    #[Test]
    public function groupByRestFile(): void
    {
        $entries = $this->parser->parseFromInstalledPackage();
        $grouped = $this->parser->groupByRestFile($entries);

        self::assertNotEmpty($grouped);

        foreach (array_keys($grouped) as $rstFile) {
            self::assertStringEndsWith('.rst', $rstFile);
        }

        // Each group must contain MatcherEntry instances
        foreach ($grouped as $rstFile => $groupEntries) {
            self::assertNotEmpty($groupEntries, sprintf('Group for "%s" is empty', $rstFile));
            self::assertContainsOnlyInstancesOf(MatcherEntry::class, $groupEntries);
        }
    }

    #[Test]
    public function findConfigDirectoryReturnsExistingPath(): void
    {
        $configDir = $this->parser->findConfigDirectory();

        self::assertNotNull($configDir);
        self::assertDirectoryExists($configDir);
    }

    #[Test]
    public function parsedEntryHasExpectedStructure(): void
    {
        $entries = $this->parser->parseFromInstalledPackage();

        // Find a known entry from MethodCallMatcher (confirmed it exists above)
        $clipboardEntry = null;

        foreach ($entries as $entry) {
            if ('TYPO3\CMS\Backend\Clipboard\Clipboard->confirmMsg' === $entry->identifier) {
                $clipboardEntry = $entry;

                break;
            }
        }

        self::assertNotNull($clipboardEntry, 'Expected entry for Clipboard->confirmMsg not found');
        self::assertSame(MatcherType::MethodCall, $clipboardEntry->matcherType);
        self::assertContains('Breaking-80700-DeprecatedFunctionalityRemoved.rst', $clipboardEntry->restFiles);
        self::assertArrayHasKey('numberOfMandatoryArguments', $clipboardEntry->additionalConfig);
    }
}
