<?php

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\MatcherType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\MatcherConfigGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
            'maximumNumberOfArguments' => 0,
        ], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generateStaticMethodMatcher(): void
    {
        $document = $this->createDocument(
            filename: 'Deprecation-34567-StaticMethodRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\Utility\GeneralUtility',
                    member: 'hmac',
                    type: CodeReferenceType::StaticMethod,
                ),
            ],
        );

        $entries = $this->generator->generate($document);

        self::assertCount(1, $entries);
        self::assertSame('TYPO3\CMS\Core\Utility\GeneralUtility::hmac', $entries[0]->identifier);
        self::assertSame(MatcherType::MethodCallStatic, $entries[0]->matcherType);
        self::assertSame(['Deprecation-34567-StaticMethodRemoved.rst'], $entries[0]->restFiles);
        self::assertSame([
            'numberOfMandatoryArguments' => 0,
            'maximumNumberOfArguments' => 0,
        ], $entries[0]->additionalConfig);
    }

    #[Test]
    public function generatePropertyProtectedMatcher(): void
    {
        $document = $this->createDocument(
            filename: 'Breaking-45678-PropertyRemoved.rst',
            codeReferences: [
                new CodeReference(
                    className: 'TYPO3\CMS\Core\DataHandling\DataHandler',
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

        $entries = $this->generator->generate($document);
        $rendered = $this->generator->renderPhp($entries);

        self::assertStringContainsString('<?php', $rendered);
        self::assertStringContainsString('return [', $rendered);
        self::assertStringContainsString("'TYPO3\\\\CMS\\\\Core\\\\OldClass'", $rendered);
        self::assertStringContainsString("'restFiles' => [", $rendered);
        self::assertStringContainsString("'Deprecation-12345-Test.rst'", $rendered);
    }

    /**
     * @param list<CodeReference> $codeReferences
     */
    private function createDocument(
        string $filename,
        array $codeReferences = [],
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
        );
    }
}
