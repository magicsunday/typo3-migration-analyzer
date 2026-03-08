<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter;

final class CodeReferenceTest extends TestCase
{
    #[Test]
    public function fromPhpRoleParsesStaticMethod(): void
    {
        $ref = CodeReference::fromPhpRole(GeneralUtility::class . '::fixPermissions()');

        self::assertNotNull($ref);
        self::assertSame(GeneralUtility::class, $ref->className);
        self::assertSame('fixPermissions', $ref->member);
        self::assertSame(CodeReferenceType::StaticMethod, $ref->type);
    }

    #[Test]
    public function fromPhpRoleParsesInstanceMethod(): void
    {
        $ref = CodeReference::fromPhpRole('TYPO3\CMS\Core\Resource\FileExtensionFilter->filterInlineChildren()');

        self::assertNotNull($ref);
        self::assertSame('TYPO3\CMS\Core\Resource\FileExtensionFilter', $ref->className);
        self::assertSame('filterInlineChildren', $ref->member);
        self::assertSame(CodeReferenceType::InstanceMethod, $ref->type);
    }

    #[Test]
    public function fromPhpRoleParsesClassName(): void
    {
        $ref = CodeReference::fromPhpRole(FileReference::class);

        self::assertNotNull($ref);
        self::assertSame(FileReference::class, $ref->className);
        self::assertNull($ref->member);
        self::assertSame(CodeReferenceType::ClassName, $ref->type);
    }

    #[Test]
    public function fromPhpRoleParsesProperty(): void
    {
        $ref = CodeReference::fromPhpRole('TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter->$sourceTypes');

        self::assertNotNull($ref);
        self::assertSame(AbstractTypeConverter::class, $ref->className);
        self::assertSame('sourceTypes', $ref->member);
        self::assertSame(CodeReferenceType::Property, $ref->type);
    }

    #[Test]
    public function fromPhpRoleParsesClassConstant(): void
    {
        $ref = CodeReference::fromPhpRole('TYPO3\CMS\Backend\Template\DocumentTemplate::STATUS_ICON_ERROR');

        self::assertNotNull($ref);
        self::assertSame('TYPO3\CMS\Backend\Template\DocumentTemplate', $ref->className);
        self::assertSame('STATUS_ICON_ERROR', $ref->member);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
    }

    #[Test]
    public function fromPhpRoleParsesPlainFunctionNameAsUnqualifiedMethod(): void
    {
        $ref = CodeReference::fromPhpRole('file');

        self::assertNotNull($ref);
        self::assertSame('', $ref->className);
        self::assertSame('file', $ref->member);
        self::assertSame(CodeReferenceType::UnqualifiedMethod, $ref->type);
        self::assertSame(0.3, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesGlobalConstantAsClassConstant(): void
    {
        $ref = CodeReference::fromPhpRole('E_USER_DEPRECATED');

        self::assertNotNull($ref);
        self::assertSame('', $ref->className);
        self::assertSame('E_USER_DEPRECATED', $ref->member);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
        self::assertSame(0.6, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleStripsLeadingBackslash(): void
    {
        $ref = CodeReference::fromPhpRole(GeneralUtility::class . '::fixPermissions()');

        self::assertNotNull($ref);
        self::assertSame(GeneralUtility::class, $ref->className);
        self::assertSame('fixPermissions', $ref->member);
        self::assertSame(CodeReferenceType::StaticMethod, $ref->type);
    }

    #[Test]
    public function fromPhpRoleParsesShortClassNameForSingleSegment(): void
    {
        $ref = CodeReference::fromPhpRole('GeneralUtility');

        self::assertNotNull($ref);
        self::assertSame('GeneralUtility', $ref->className);
        self::assertNull($ref->member);
        self::assertSame(CodeReferenceType::ShortClassName, $ref->type);
        self::assertSame(0.7, $ref->resolutionConfidence);
    }

    #[Test]
    #[DataProvider('provideClassConstantPatterns')]
    public function fromPhpRoleRecognizesClassConstants(string $input, string $expectedMember): void
    {
        $ref = CodeReference::fromPhpRole($input);

        self::assertNotNull($ref);
        self::assertSame($expectedMember, $ref->member);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideClassConstantPatterns(): iterable
    {
        yield 'all uppercase' => [
            'TYPO3\CMS\Core\SomeClass::SOME_CONSTANT',
            'SOME_CONSTANT',
        ];

        yield 'uppercase with digits' => [
            'TYPO3\CMS\Core\SomeClass::VERSION_2',
            'VERSION_2',
        ];
    }

    #[Test]
    public function fromPhpRoleReturnsNullForEmptyString(): void
    {
        self::assertNull(CodeReference::fromPhpRole(''));
    }

    #[Test]
    public function fromPhpRoleTreatsAllUppercaseWithParenthesesAsStaticMethod(): void
    {
        $ref = CodeReference::fromPhpRole('TYPO3\CMS\Core\SomeClass::GET()');

        self::assertNotNull($ref);
        self::assertSame('GET', $ref->member);
        self::assertSame(CodeReferenceType::StaticMethod, $ref->type);
    }

    #[Test]
    public function fromPhpRoleTreatsAllUppercaseWithoutParenthesesAsConstant(): void
    {
        $ref = CodeReference::fromPhpRole('TYPO3\CMS\Core\SomeClass::GET');

        self::assertNotNull($ref);
        self::assertSame('GET', $ref->member);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
    }

    #[Test]
    public function fromPhpRoleReturnsNullForOnlyBackslash(): void
    {
        self::assertNull(CodeReference::fromPhpRole('\\'));
    }

    #[Test]
    public function fromPhpRoleSetsFullConfidenceForFqcn(): void
    {
        $ref = CodeReference::fromPhpRole(GeneralUtility::class . '::fixPermissions()');

        self::assertNotNull($ref);
        self::assertSame(1.0, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesShortClassName(): void
    {
        $ref = CodeReference::fromPhpRole('ConfigurationView');

        self::assertNotNull($ref);
        self::assertSame('ConfigurationView', $ref->className);
        self::assertNull($ref->member);
        self::assertSame(CodeReferenceType::ShortClassName, $ref->type);
        self::assertSame(0.7, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesUnqualifiedMethod(): void
    {
        $ref = CodeReference::fromPhpRole('getIdentifier()');

        self::assertNotNull($ref);
        self::assertSame('', $ref->className);
        self::assertSame('getIdentifier', $ref->member);
        self::assertSame(CodeReferenceType::UnqualifiedMethod, $ref->type);
        self::assertSame(0.5, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesPropertyWithoutClass(): void
    {
        $ref = CodeReference::fromPhpRole('$sourceTypes');

        self::assertNotNull($ref);
        self::assertSame('', $ref->className);
        self::assertSame('sourceTypes', $ref->member);
        self::assertSame(CodeReferenceType::Property, $ref->type);
        self::assertSame(0.6, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesUnqualifiedConstant(): void
    {
        $ref = CodeReference::fromPhpRole('SOME_CONSTANT');

        self::assertNotNull($ref);
        self::assertSame('', $ref->className);
        self::assertSame('SOME_CONSTANT', $ref->member);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
        self::assertSame(0.6, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesConfigKey(): void
    {
        $ref = CodeReference::fromPhpRole('config.contentObjectExceptionHandler');

        self::assertNotNull($ref);
        self::assertSame('config.contentObjectExceptionHandler', $ref->className);
        self::assertNull($ref->member);
        self::assertSame(CodeReferenceType::ConfigKey, $ref->type);
        self::assertSame(0.4, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesShortClassStaticMethod(): void
    {
        $ref = CodeReference::fromPhpRole('AbstractRecordList::writeBottom()');

        self::assertNotNull($ref);
        self::assertSame('AbstractRecordList', $ref->className);
        self::assertSame('writeBottom', $ref->member);
        self::assertSame(CodeReferenceType::StaticMethod, $ref->type);
        self::assertSame(0.5, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesShortClassStaticConstant(): void
    {
        $ref = CodeReference::fromPhpRole('SomeClass::MY_CONSTANT');

        self::assertNotNull($ref);
        self::assertSame('SomeClass', $ref->className);
        self::assertSame('MY_CONSTANT', $ref->member);
        self::assertSame(CodeReferenceType::ClassConstant, $ref->type);
        self::assertSame(0.5, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesShortClassInstanceMethod(): void
    {
        $ref = CodeReference::fromPhpRole('DataMapper->setQuery()');

        self::assertNotNull($ref);
        self::assertSame('DataMapper', $ref->className);
        self::assertSame('setQuery', $ref->member);
        self::assertSame(CodeReferenceType::InstanceMethod, $ref->type);
        self::assertSame(0.5, $ref->resolutionConfidence);
    }

    #[Test]
    public function fromPhpRoleParsesShortClassInstanceProperty(): void
    {
        $ref = CodeReference::fromPhpRole('ActionController->$extensionName');

        self::assertNotNull($ref);
        self::assertSame('ActionController', $ref->className);
        self::assertSame('extensionName', $ref->member);
        self::assertSame(CodeReferenceType::Property, $ref->type);
        self::assertSame(0.5, $ref->resolutionConfidence);
    }

    #[Test]
    #[DataProvider('providePhpKeywords')]
    public function fromPhpRoleReturnsNullForPhpKeywords(string $keyword): void
    {
        self::assertNull(CodeReference::fromPhpRole($keyword));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePhpKeywords(): iterable
    {
        yield 'true' => ['true'];
        yield 'false' => ['false'];
        yield 'null' => ['null'];
        yield 'array' => ['array'];
        yield 'mixed' => ['mixed'];
        yield 'string' => ['string'];
        yield 'int' => ['int'];
        yield 'void' => ['void'];
        yield 'self' => ['self'];
        yield 'static' => ['static'];
        yield 'parent' => ['parent'];
        yield '@internal' => ['@internal'];
        yield 'new' => ['new'];
        yield 'float' => ['float'];
        yield 'bool' => ['bool'];
        yield 'never' => ['never'];
        yield 'callable' => ['callable'];
        yield 'iterable' => ['iterable'];
        yield 'object' => ['object'];
    }
}
