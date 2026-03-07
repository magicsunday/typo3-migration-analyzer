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
use TYPO3\CMS\Core\Type\Enumeration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Property\TypeConverter\AbstractTypeConverter;

final class CodeReferenceTest extends TestCase
{
    #[Test]
    public function fromPhpRoleParsesStaticMethod(): void
    {
        $ref = CodeReference::fromPhpRole(GeneralUtility::class . '::hmac()');

        self::assertNotNull($ref);
        self::assertSame(GeneralUtility::class, $ref->className);
        self::assertSame('hmac', $ref->member);
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
        $ref = CodeReference::fromPhpRole(Enumeration::class);

        self::assertNotNull($ref);
        self::assertSame(Enumeration::class, $ref->className);
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
    public function fromPhpRoleReturnsNullForPlainFunctionName(): void
    {
        self::assertNull(CodeReference::fromPhpRole('file'));
    }

    #[Test]
    public function fromPhpRoleReturnsNullForGlobalConstant(): void
    {
        self::assertNull(CodeReference::fromPhpRole('E_USER_DEPRECATED'));
    }

    #[Test]
    public function fromPhpRoleStripsLeadingBackslash(): void
    {
        $ref = CodeReference::fromPhpRole(GeneralUtility::class . '::hmac()');

        self::assertNotNull($ref);
        self::assertSame(GeneralUtility::class, $ref->className);
        self::assertSame('hmac', $ref->member);
        self::assertSame(CodeReferenceType::StaticMethod, $ref->type);
    }

    #[Test]
    public function fromPhpRoleReturnsNullForSingleSegmentNamespace(): void
    {
        self::assertNull(CodeReference::fromPhpRole('GeneralUtility'));
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
}
