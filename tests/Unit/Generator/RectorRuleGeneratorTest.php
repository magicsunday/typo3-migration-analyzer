<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Analyzer\MigrationMappingExtractor;
use App\Dto\CodeReference;
use App\Dto\CodeReferenceType;
use App\Dto\DocumentType;
use App\Dto\RectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\RectorRuleGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

final class RectorRuleGeneratorTest extends TestCase
{
    private RectorRuleGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new RectorRuleGenerator(
            new MigrationMappingExtractor(),
        );
    }

    #[Test]
    public function generateClassRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $rules       = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameClass, $configRules[0]->type);
        self::assertSame('TYPO3\CMS\Core\OldClass', $configRules[0]->source->className);
        self::assertNotNull($configRules[0]->target);
        self::assertSame('TYPO3\CMS\Core\NewClass', $configRules[0]->target->className);
        self::assertTrue($configRules[0]->isConfig());
    }

    #[Test]
    public function generateStaticMethodRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: ':php:`\TYPO3\CMS\Core\Service::oldMethod()` has been renamed '
                . 'to :php:`\TYPO3\CMS\Core\Service::newMethod()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Service', 'oldMethod', CodeReferenceType::StaticMethod),
            ],
        );

        $rules       = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameStaticMethod, $configRules[0]->type);
        self::assertSame('oldMethod', $configRules[0]->source->member);
        self::assertNotNull($configRules[0]->target);
        self::assertSame('newMethod', $configRules[0]->target->member);
    }

    #[Test]
    public function generateInstanceMethodRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\Foo->oldMethod()` with '
                . ':php:`\TYPO3\CMS\Core\Foo->newMethod()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Foo', 'oldMethod', CodeReferenceType::InstanceMethod),
            ],
        );

        $rules       = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameMethod, $configRules[0]->type);
    }

    #[Test]
    public function generateConstantRenameConfigRule(): void
    {
        $doc = $this->createDocument(
            migration: ':php:`\TYPO3\CMS\Core\Conf::OLD_CONST` has been renamed '
                . 'to :php:`\TYPO3\CMS\Core\Conf::NEW_CONST`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Conf', 'OLD_CONST', CodeReferenceType::ClassConstant),
            ],
        );

        $rules       = $this->generator->generate($doc);
        $configRules = $this->filterConfig($rules);

        self::assertCount(1, $configRules);
        self::assertSame(RectorRuleType::RenameClassConstant, $configRules[0]->type);
    }

    #[Test]
    public function generateSkeletonForCodeRefWithoutMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'There is no direct replacement.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\Legacy', null, CodeReferenceType::ClassName),
            ],
        );

        $rules         = $this->generator->generate($doc);
        $skeletonRules = $this->filterSkeletons($rules);

        self::assertCount(1, $skeletonRules);
        self::assertSame(RectorRuleType::Skeleton, $skeletonRules[0]->type);
        self::assertNull($skeletonRules[0]->target);
        self::assertFalse($skeletonRules[0]->isConfig());
    }

    #[Test]
    public function generateSkeletonForCodeRefNotCoveredByMapping(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewClass`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
                new CodeReference('TYPO3\CMS\Core\SecondClass', null, CodeReferenceType::ClassName),
            ],
        );

        $rules = $this->generator->generate($doc);

        self::assertCount(2, $rules);
        self::assertCount(1, $this->filterConfig($rules));
        self::assertCount(1, $this->filterSkeletons($rules));
    }

    #[Test]
    public function generateReturnsEmptyForNoCodeRefsAndNoMappings(): void
    {
        $doc = $this->createDocument(migration: null, codeReferences: []);

        self::assertSame([], $this->generator->generate($doc));
    }

    #[Test]
    public function generateSkeletonForMismatchedTypes(): void
    {
        $doc = $this->createDocument(
            migration: 'Replace :php:`\TYPO3\CMS\Core\OldClass` with :php:`\TYPO3\CMS\Core\NewService::create()`.',
            codeReferences: [
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            ],
        );

        $rules = $this->generator->generate($doc);

        self::assertCount(0, $this->filterConfig($rules));
    }

    #[Test]
    public function renderConfigForClassRename(): void
    {
        $rules = [
            new RectorRule(
                RectorRuleType::RenameClass,
                new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
                new CodeReference('TYPO3\CMS\Core\NewClass', null, CodeReferenceType::ClassName),
                'Test',
                'Test.rst',
            ),
        ];

        $output = $this->generator->renderConfig($rules);

        self::assertStringContainsString('declare(strict_types=1);', $output);
        self::assertStringContainsString('use Rector\Config\RectorConfig;', $output);
        self::assertStringContainsString('RenameClassRector::class', $output);
        self::assertStringContainsString("'TYPO3\\\\CMS\\\\Core\\\\OldClass' => 'TYPO3\\\\CMS\\\\Core\\\\NewClass'", $output);
    }

    #[Test]
    public function renderConfigForMethodRename(): void
    {
        $rules = [
            new RectorRule(
                RectorRuleType::RenameMethod,
                new CodeReference('TYPO3\CMS\Core\Foo', 'oldMethod', CodeReferenceType::InstanceMethod),
                new CodeReference('TYPO3\CMS\Core\Foo', 'newMethod', CodeReferenceType::InstanceMethod),
                'Test',
                'Test.rst',
            ),
        ];

        $output = $this->generator->renderConfig($rules);

        self::assertStringContainsString('RenameMethodRector::class', $output);
        self::assertStringContainsString('MethodCallRename', $output);
        self::assertStringContainsString("'TYPO3\\\\CMS\\\\Core\\\\Foo'", $output);
        self::assertStringContainsString("'oldMethod'", $output);
        self::assertStringContainsString("'newMethod'", $output);
    }

    #[Test]
    public function renderConfigForStaticMethodRename(): void
    {
        $rules = [
            new RectorRule(
                RectorRuleType::RenameStaticMethod,
                new CodeReference('TYPO3\CMS\Core\Old', 'calc', CodeReferenceType::StaticMethod),
                new CodeReference('TYPO3\CMS\Core\New', 'compute', CodeReferenceType::StaticMethod),
                'Test',
                'Test.rst',
            ),
        ];

        $output = $this->generator->renderConfig($rules);

        self::assertStringContainsString('RenameStaticMethodRector::class', $output);
        self::assertStringContainsString('RenameStaticMethod(', $output);
    }

    #[Test]
    public function renderConfigForConstantRename(): void
    {
        $rules = [
            new RectorRule(
                RectorRuleType::RenameClassConstant,
                new CodeReference('TYPO3\CMS\Core\Conf', 'OLD_CONST', CodeReferenceType::ClassConstant),
                new CodeReference('TYPO3\CMS\Core\Conf', 'NEW_CONST', CodeReferenceType::ClassConstant),
                'Test',
                'Test.rst',
            ),
        ];

        $output = $this->generator->renderConfig($rules);

        self::assertStringContainsString('RenameClassConstFetchRector::class', $output);
        self::assertStringContainsString('RenameClassAndConstFetch(', $output);
    }

    #[Test]
    public function renderConfigReturnsEmptyStringForNoConfigRules(): void
    {
        self::assertSame('', $this->generator->renderConfig([]));
    }

    #[Test]
    public function renderConfigSkipsSkeletonRules(): void
    {
        $rules = [
            new RectorRule(
                RectorRuleType::Skeleton,
                new CodeReference('TYPO3\CMS\Core\Legacy', null, CodeReferenceType::ClassName),
                null,
                'Test',
                'Test.rst',
            ),
        ];

        self::assertSame('', $this->generator->renderConfig($rules));
    }

    #[Test]
    public function renderSkeletonProducesValidPhpClass(): void
    {
        $rule = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Legacy\OldClass', 'doSomething', CodeReferenceType::InstanceMethod),
            null,
            'Deprecation: #99999 - OldClass deprecated',
            'Deprecation-99999-OldClassDeprecated.rst',
        );

        $output = $this->generator->renderSkeleton($rule);

        self::assertStringContainsString('declare(strict_types=1);', $output);
        self::assertStringContainsString('final class OldClassDeprecatedRector extends AbstractRector', $output);
        self::assertStringContainsString('getRuleDefinition', $output);
        self::assertStringContainsString('getNodeTypes', $output);
        self::assertStringContainsString('refactor', $output);
        self::assertStringContainsString('Deprecation: #99999 - OldClass deprecated', $output);
        self::assertStringContainsString('Deprecation-99999-OldClassDeprecated.rst', $output);
        self::assertStringContainsString('MethodCall::class', $output);
        self::assertStringContainsString('TYPO3\CMS\Core\Legacy\OldClass->doSomething', $output);
    }

    #[Test]
    public function renderSkeletonUsesCorrectNodeTypeForStaticMethod(): void
    {
        $rule = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Utility', 'method', CodeReferenceType::StaticMethod),
            null,
            'Test',
            'Deprecation-11111-Test.rst',
        );

        $output = $this->generator->renderSkeleton($rule);

        self::assertStringContainsString('StaticCall::class', $output);
    }

    #[Test]
    public function renderSkeletonUsesCorrectNodeTypeForClassName(): void
    {
        $rule = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\OldClass', null, CodeReferenceType::ClassName),
            null,
            'Test',
            'Breaking-22222-ClassRemoved.rst',
        );

        $output = $this->generator->renderSkeleton($rule);

        self::assertStringContainsString('FullyQualified::class', $output);
        self::assertStringContainsString('ClassRemovedRector', $output);
    }

    #[Test]
    public function renderSkeletonClassNameDerivedFromFilename(): void
    {
        $rule = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Foo', null, CodeReferenceType::ClassName),
            null,
            'Test',
            'Deprecation-55555-SomeComplexFeature.rst',
        );

        $output = $this->generator->renderSkeleton($rule);

        self::assertStringContainsString('SomeComplexFeatureRector', $output);
    }

    #[Test]
    public function generateClassNameConvertsHyphensToPascalCase(): void
    {
        $rule = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Foo', null, CodeReferenceType::ClassName),
            null,
            'Test',
            'Breaking-94243-SendUserSessionCookiesAsHash-signedJWT.rst',
        );

        self::assertSame('SendUserSessionCookiesAsHashSignedJWTRector', $this->generator->generateClassName($rule));
    }

    #[Test]
    public function generateClassNameConvertsUnderscoresAndDots(): void
    {
        $rule = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Foo', null, CodeReferenceType::ClassName),
            null,
            'Test',
            'Deprecation-12345-AddUppercamelcaseToStdWrap.case.rst',
        );

        self::assertSame('AddUppercamelcaseToStdWrapCaseRector', $this->generator->generateClassName($rule));

        $rule2 = new RectorRule(
            RectorRuleType::Skeleton,
            new CodeReference('TYPO3\CMS\Core\Foo', null, CodeReferenceType::ClassName),
            null,
            'Test',
            'Deprecation-12345-Ext_makeToolbarDeprecated.rst',
        );

        self::assertSame('ExtMakeToolbarDeprecatedRector', $this->generator->generateClassName($rule2));
    }

    /**
     * @param list<CodeReference> $codeReferences
     */
    private function createDocument(
        ?string $migration,
        array $codeReferences = [],
        string $filename = 'Deprecation-99999-Test.rst',
    ): RstDocument {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 99999,
            title: 'Test document',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: $migration,
            codeReferences: $codeReferences,
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: $filename,
        );
    }

    /**
     * @param list<RectorRule> $rules
     *
     * @return list<RectorRule>
     */
    private function filterConfig(array $rules): array
    {
        return array_values(array_filter($rules, static fn (RectorRule $r): bool => $r->isConfig()));
    }

    /**
     * @param list<RectorRule> $rules
     *
     * @return list<RectorRule>
     */
    private function filterSkeletons(array $rules): array
    {
        return array_values(array_filter($rules, static fn (RectorRule $r): bool => !$r->isConfig()));
    }
}
