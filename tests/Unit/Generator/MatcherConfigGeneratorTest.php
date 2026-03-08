<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Dto\CodeBlock;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\MatcherEntry;
use App\Dto\MatcherType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\MatcherConfigGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class MatcherConfigGeneratorTest extends TestCase
{
    private MatcherConfigGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MatcherConfigGenerator();
    }

    #[Test]
    public function generateClassNameMatcherForRemovedClass(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-12345-RemovedClass.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\OldClass',
                    member: null,
                    type: CodeReferenceType::ClassName,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame('TYPO3\CMS\Core\OldClass', $entries[0]->identifier);
        self::assertSame(MatcherType::ClassName, $entries[0]->matcherType);
        self::assertSame(['Deprecation-12345-RemovedClass.rst'], $entries[0]->restFiles);
        self::assertSame([], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generateMethodCallMatcherForInstanceMethod(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-23456-MethodRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\Foo',
                    member: 'bar',
                    type: CodeReferenceType::InstanceMethod,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame('TYPO3\CMS\Core\Foo->bar', $entries[0]->identifier);
        self::assertSame(MatcherType::MethodCall, $entries[0]->matcherType);
        self::assertSame(['Deprecation-23456-MethodRemoved.rst'], $entries[0]->restFiles);
        self::assertSame([
            'numberOfMandatoryArguments' => 0,
            'maximumNumberOfArguments'   => 0,
        ], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generateStaticMethodMatcher(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-34567-StaticMethodRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: GeneralUtility::class,
                    member: 'hmac',
                    type: CodeReferenceType::StaticMethod,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame(GeneralUtility::class . '::hmac', $entries[0]->identifier);
        self::assertSame(MatcherType::MethodCallStatic, $entries[0]->matcherType);
        self::assertSame(['Deprecation-34567-StaticMethodRemoved.rst'], $entries[0]->restFiles);
        self::assertSame([
            'numberOfMandatoryArguments' => 1,
            'maximumNumberOfArguments'   => 2,
        ], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generatePropertyProtectedMatcher(): void
    {
        $document = $this->createDocument(
            filename: 'Breaking-45678-PropertyRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: DataHandler::class,
                    member: 'recUpdateAccessCache',
                    type: CodeReferenceType::Property,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame(
            'TYPO3\CMS\Core\DataHandling\DataHandler->recUpdateAccessCache',
            $entries[0]->identifier,
        );
        self::assertSame(MatcherType::PropertyProtected, $entries[0]->matcherType);
        self::assertSame(['Breaking-45678-PropertyRemoved.rst'], $entries[0]->restFiles);
        self::assertSame([], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generateClassConstantMatcher(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-56789-ConstantRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Backend\Template\DocumentTemplate',
                    member: 'STATUS_ICON_ERROR',
                    type: CodeReferenceType::ClassConstant,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame(
            'TYPO3\CMS\Backend\Template\DocumentTemplate::STATUS_ICON_ERROR',
            $entries[0]->identifier,
        );
        self::assertSame(MatcherType::ClassConstant, $entries[0]->matcherType);
        self::assertSame(['Deprecation-56789-ConstantRemoved.rst'], $entries[0]->restFiles);
        self::assertSame([], $entries[0]->additionalConfig);
    }

    #[Test]
    public function renderAsPhpArray(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-12345-Test.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\OldClass',
                    member: null,
                    type: CodeReferenceType::ClassName,
                ),
            ],
        );

        $entries  = $this->generator->generate($document);
        $rendered = $this->generator->renderPhp($entries);

        self::assertStringContainsString('<?php', $rendered);
        self::assertStringContainsString('return [', $rendered);
        self::assertStringContainsString("'TYPO3\\\\CMS\\\\Core\\\\OldClass'", $rendered);
        self::assertStringContainsString("'restFiles' => [", $rendered);
        self::assertStringContainsString("'Deprecation-12345-Test.rst'", $rendered);
    }

    #[Test]
    public function generateReturnsEmptyArrayForDocumentWithoutCodeReferences(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-99999-NoRefs.rst',
            codeReferences: [],
        );

        $entries = $this->generator->generate($document);

        self::assertSame([], $entries);
    }

    #[Test]
    public function renderPhpHandlesIntegerConfigValues(): void
    {
        $entry = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Test.rst'],
            additionalConfig: [
                'numberOfMandatoryArguments' => 2,
                'maximumNumberOfArguments'   => 5,
            ],
        );

        $rendered = $this->generator->renderPhp([$entry]);

        self::assertStringContainsString("'numberOfMandatoryArguments' => 2,", $rendered);
        self::assertStringContainsString("'maximumNumberOfArguments' => 5,", $rendered);
    }

    #[Test]
    public function renderPhpHandlesBooleanConfigValues(): void
    {
        $entry = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Test.rst'],
            additionalConfig: [
                'someFlag'    => true,
                'anotherFlag' => false,
            ],
        );

        $rendered = $this->generator->renderPhp([$entry]);

        self::assertStringContainsString("'someFlag' => true,", $rendered);
        self::assertStringContainsString("'anotherFlag' => false,", $rendered);
    }

    #[Test]
    public function renderPhpHandlesStringConfigValues(): void
    {
        $entry = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCall,
            restFiles: ['Test.rst'],
            additionalConfig: [
                'replacement' => 'newMethod',
            ],
        );

        $rendered = $this->generator->renderPhp([$entry]);

        self::assertStringContainsString("'replacement' => 'newMethod',", $rendered);
    }

    #[Test]
    public function renderPhpHandlesArrayConfigValues(): void
    {
        $entry = new MatcherEntry(
            identifier: 'TYPO3\CMS\Core\Foo->bar',
            matcherType: MatcherType::MethodCallStatic,
            restFiles: ['Test.rst'],
            additionalConfig: [
                'unusedArgumentNumbers' => [1, 2],
            ],
        );

        $rendered = $this->generator->renderPhp([$entry]);

        self::assertStringContainsString('unusedArgumentNumbers', $rendered);
        self::assertStringContainsString('array', $rendered);
    }

    #[Test]
    public function renderPhpProducesEmptyReturnArrayForEmptyEntries(): void
    {
        $rendered = $this->generator->renderPhp([]);

        self::assertSame("<?php\n\nreturn [\n];\n", $rendered);
    }

    #[Test]
    public function generateDetectsArgumentsFromCodeBlocks(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-11111-MethodChanged.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\SomeClass',
                    member: 'doSomething',
                    type: CodeReferenceType::InstanceMethod,
                ),
            ],
            codeBlocks: [
                new CodeBlock(
                    language: 'php',
                    code: 'public function doSomething(string $name, int $age, string $title = \'\')',
                    label: null,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame([
            'numberOfMandatoryArguments' => 2,
            'maximumNumberOfArguments'   => 3,
        ], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generateFallsBackToZeroWhenNoSignatureFound(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-22222-UnknownMethod.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\NonExistentClass',
                    member: 'nonExistentMethod',
                    type: CodeReferenceType::InstanceMethod,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame([
            'numberOfMandatoryArguments' => 0,
            'maximumNumberOfArguments'   => 0,
        ], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generateDetectsArgumentsViaReflectionFallback(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-33333-HmacRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: GeneralUtility::class,
                    member: 'hmac',
                    type: CodeReferenceType::StaticMethod,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame([
            'numberOfMandatoryArguments' => 1,
            'maximumNumberOfArguments'   => 2,
        ], $entries[0]->additionalConfig);
    }

    /**
     * @param list<CodeReference> $codeReferences
     * @param list<CodeBlock>     $codeBlocks
     */
    private function createDocument(
        string $filename,
        array $codeReferences = [],
        array $codeBlocks = [],
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 0,
            title: 'Test document',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: null,
            codeReferences: $codeReferences,
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
            codeBlocks: $codeBlocks,
        );
    }
}
