<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Analyzer;

use App\Analyzer\ComplexityScorer;
use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeBlock;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplexityScorer::class)]
final class ComplexityScorerTest extends TestCase
{
    private ComplexityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ComplexityScorer(new MigrationMappingExtractor());
    }

    #[Test]
    public function scoreClassRenamedWithMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreMethodRenamedWithMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\Foo::oldMethod()` with :php:`\TYPO3\CMS\Core\Foo::newMethod()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'oldMethod', CodeReferenceType::StaticMethod),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreMethodRemovedWithClearReplacement(): void
    {
        $doc = $this->createDocument(
            migration: 'Use :php:`\TYPO3\CMS\Core\NewClass` instead of :php:`\TYPO3\CMS\Core\OldClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', 'doSomething', CodeReferenceType::InstanceMethod),
                new CodeReference('TYPO3\CMS\Core\OldClass', 'doOther', CodeReferenceType::InstanceMethod),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(2, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreArgumentSignatureChange(): void
    {
        $doc = $this->createDocument(
            migration: 'The method signature has changed. Adapt your code accordingly.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'bar', CodeReferenceType::InstanceMethod),
            ],
            codeBlocks: [
                new CodeBlock('php', 'public function bar(string $a, int $b, bool $c = false): void {}', 'After'),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(3, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreHookToEventMigration(): void
    {
        $doc = $this->createDocument(
            migration: 'Use the PSR-14 event :php:`\TYPO3\CMS\Core\Imaging\Event\IconOverlayEvent` instead.',
            title: 'Removed hook for overriding icon overlay identifier',
            indexTags: ['ext:core', 'Hook'],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(4, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreTcaRestructure(): void
    {
        $doc = $this->createDocument(
            migration: 'Migrate your TCA configuration to use the new type.',
            title: 'TCA type bitmask removed',
            indexTags: ['TCA'],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(4, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreArchitectureChangeWithoutReplacement(): void
    {
        $doc = $this->createDocument(
            migration: 'The entire subsystem has been redesigned. Review your architecture.',
            codeReferences: [],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(4, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreDocumentWithNoMigrationText(): void
    {
        $doc = $this->createDocument(
            migration: null,
            codeReferences: [],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(5, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scorePropertyChangeWithMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'The property :php:`\TYPO3\CMS\Core\Foo::$old` has been renamed to :php:`\TYPO3\CMS\Core\Foo::$new`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'old', CodeReferenceType::Property),
            ],
        );

        $result = $this->scorer->score($doc);

        // Property changes with mappings are simple renames
        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreMigrationWithReplaceKeywordAndCodeBlocks(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace `templatePathAndFilename` with the `templateName` and `templateRootPaths` options.',
            codeBlocks: [
                new CodeBlock('yaml', "templateName: 'MyTemplate'", null),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(2, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMigrationWithUseInsteadKeyword(): void
    {
        $doc = $this->createDocument(
            migration: 'Use the already available icon identifiers from TYPO3.Icons instead.',
        );

        $result = $this->scorer->score($doc);

        self::assertSame(3, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMigrationWithCodeBlocksButNoRefs(): void
    {
        $doc = $this->createDocument(
            migration: 'Extensions registering custom Content Objects should now use the service configuration.',
            codeBlocks: [
                new CodeBlock('yaml', "services:\n  MyVendor\\MyExt:", null),
                new CodeBlock('yaml', "services:\n  MyVendor\\MyExt:", 'After'),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(2, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMigrationWithJustRemoveInstruction(): void
    {
        $doc = $this->createDocument(
            migration: 'Calling this method is not needed anymore and can be removed from the affected code.',
        );

        $result = $this->scorer->score($doc);

        self::assertSame(3, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMigrationWithNoReplacementKeyword(): void
    {
        $doc = $this->createDocument(
            migration: 'There is no direct replacement. Manual review of your architecture is required.',
        );

        $result = $this->scorer->score($doc);

        self::assertSame(5, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMigrationWithRenameKeyword(): void
    {
        $doc = $this->createDocument(
            migration: 'Rename the files to `ext_typoscript_setup.typoscript` and `ext_typoscript_constants.typoscript`.',
        );

        $result = $this->scorer->score($doc);

        self::assertSame(3, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMappingsWithoutCodeReferences(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(1, $result->score);
        self::assertTrue($result->automatable);
    }

    #[Test]
    public function scoreMigrationTextWithSwitchToKeyword(): void
    {
        $doc = $this->createDocument(
            migration: 'Switch to the new PageRenderer API for adding JavaScript modules.',
        );

        $result = $this->scorer->score($doc);

        self::assertSame(3, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreTriviallyShortMigrationText(): void
    {
        $doc = $this->createDocument(
            migration: 'None.',
        );

        $result = $this->scorer->score($doc);

        self::assertSame(5, $result->score);
        self::assertFalse($result->automatable);
    }

    #[Test]
    public function scoreMigrationTextWithMigrateToKeyword(): void
    {
        $doc = $this->createDocument(
            migration: 'Migrate to the new content element registration via TCA.',
            codeBlocks: [
                new CodeBlock('php', '$GLOBALS["TCA"]["tt_content"]["types"]["my_type"] = [...]', 'After'),
            ],
        );

        $result = $this->scorer->score($doc);

        self::assertSame(2, $result->score);
        self::assertFalse($result->automatable);
    }

    /**
     * @param list<CodeReference> $codeReferences
     * @param list<CodeBlock>     $codeBlocks
     * @param list<string>        $indexTags
     */
    private function createDocument(
        ?string $migration = '',
        array $codeReferences = [],
        array $codeBlocks = [],
        string $title = 'Test document',
        array $indexTags = [],
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 0,
            title: $title,
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: $migration,
            codeReferences: $codeReferences,
            indexTags: $indexTags,
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-99999-Test.rst',
            codeBlocks: $codeBlocks,
        );
    }
}
