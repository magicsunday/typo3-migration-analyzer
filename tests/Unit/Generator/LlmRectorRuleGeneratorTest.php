<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Generator;

use App\Dto\AutomationGrade;
use App\Dto\CodeBlock;
use App\Dto\DocumentType;
use App\Dto\LlmAnalysisResult;
use App\Dto\LlmCodeMapping;
use App\Dto\LlmRectorAssessment;
use App\Dto\LlmRectorRule;
use App\Dto\RectorRuleType;
use App\Dto\RstDocument;
use App\Dto\ScanStatus;
use App\Generator\LlmRectorRuleGenerator;
use App\Generator\RectorConfigRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

/**
 * Tests for the LlmRectorRuleGenerator.
 */
#[CoversClass(LlmRectorRuleGenerator::class)]
final class LlmRectorRuleGeneratorTest extends TestCase
{
    private LlmRectorRuleGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new LlmRectorRuleGenerator(new RectorConfigRenderer());
    }

    #[Test]
    public function generateClassRenameProducesConfigRule(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\OldClass', 'TYPO3\CMS\Core\NewClass', 'class_rename'),
        ]);

        $rules       = $this->generator->generate($result, $this->createDocument());
        $configRules = $this->filterByType($rules, RectorRuleType::RenameClass);

        self::assertCount(1, $configRules);
        self::assertSame('RenameClassRector', $configRules[0]->ruleClassName);
        self::assertNotNull($configRules[0]->configPhp);
        self::assertNull($configRules[0]->rulePhp);
    }

    #[Test]
    public function generateMethodRenameProducesConfigRule(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\Foo::oldMethod', 'TYPO3\CMS\Core\Foo::newMethod', 'method_rename'),
        ]);

        $rules       = $this->generator->generate($result, $this->createDocument());
        $configRules = $this->filterByType($rules, RectorRuleType::RenameMethod);

        self::assertCount(1, $configRules);
        self::assertSame('RenameMethodRector', $configRules[0]->ruleClassName);
    }

    #[Test]
    public function generateMethodRenameWithArrowSyntax(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\Foo->oldMethod()', 'TYPO3\CMS\Core\Foo->newMethod()', 'method_rename'),
        ]);

        $rules       = $this->generator->generate($result, $this->createDocument());
        $configRules = $this->filterByType($rules, RectorRuleType::RenameMethod);

        self::assertCount(1, $configRules);
    }

    #[Test]
    public function generateConstantRenameProducesConfigRule(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\Conf::OLD_CONST', 'TYPO3\CMS\Core\Conf::NEW_CONST', 'constant_rename'),
        ]);

        $rules       = $this->generator->generate($result, $this->createDocument());
        $configRules = $this->filterByType($rules, RectorRuleType::RenameClassConstant);

        self::assertCount(1, $configRules);
        self::assertSame('RenameClassConstFetchRector', $configRules[0]->ruleClassName);
    }

    #[Test]
    public function generateHookToEventProducesSkeletonRule(): void
    {
        $result = $this->createLlmResult(
            [new LlmCodeMapping('$GLOBALS[TYPO3_CONF_VARS][SC_OPTIONS]', 'MyEvent', 'hook_to_event')],
            new LlmRectorAssessment(true, 'CustomRector', 'Needs custom rule'),
        );

        $doc = $this->createDocument(codeBlocks: [
            new CodeBlock('php', '$GLOBALS[\'TYPO3_CONF_VARS\'][\'SC_OPTIONS\'] = ...;', 'Before'),
            new CodeBlock('php', '$eventDispatcher->dispatch(new MyEvent());', 'After'),
        ]);
        $rules = $this->generator->generate($result, $doc);

        $skeletons = $this->filterByType($rules, RectorRuleType::Skeleton);

        self::assertCount(1, $skeletons);
        self::assertNotNull($skeletons[0]->rulePhp);
        self::assertStringContainsString('AbstractRector', $skeletons[0]->rulePhp);
        self::assertNotNull($skeletons[0]->testPhp);
        self::assertNotNull($skeletons[0]->fixtureBeforePhp);
        self::assertNotNull($skeletons[0]->fixtureAfterPhp);
    }

    #[Test]
    public function generateSkeletonUsesRectorAssessmentRuleTypeForClassName(): void
    {
        $result = $this->createLlmResult(
            [new LlmCodeMapping('SomeHook', 'SomeEvent', 'hook_to_event')],
            new LlmRectorAssessment(true, 'MigrateHookToEventRector', 'Custom'),
        );

        $rules     = $this->generator->generate($result, $this->createDocument());
        $skeletons = $this->filterByType($rules, RectorRuleType::Skeleton);

        self::assertCount(1, $skeletons);
        self::assertSame('MigrateHookToEventRector', $skeletons[0]->ruleClassName);
    }

    #[Test]
    public function generateSkeletonFallsBackToFilenameForClassName(): void
    {
        $result = $this->createLlmResult(
            [new LlmCodeMapping('SomeHook', 'SomeEvent', 'hook_to_event')],
        );

        $rules     = $this->generator->generate($result, $this->createDocument());
        $skeletons = $this->filterByType($rules, RectorRuleType::Skeleton);

        self::assertCount(1, $skeletons);
        self::assertSame('TestRector', $skeletons[0]->ruleClassName);
    }

    #[Test]
    public function generateWithNoMappingsReturnsEmpty(): void
    {
        $result = $this->createLlmResult([]);
        $rules  = $this->generator->generate($result, $this->createDocument());

        self::assertSame([], $rules);
    }

    #[Test]
    public function generateClassRemovalProducesSkeletonRule(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('TYPO3\CMS\Core\OldClass', null, 'class_removal'),
        ]);

        $rules     = $this->generator->generate($result, $this->createDocument());
        $skeletons = $this->filterByType($rules, RectorRuleType::Skeleton);

        self::assertCount(1, $skeletons);
    }

    #[Test]
    public function renderCombinedConfigRendersAllConfigRules(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('Old\A', 'New\A', 'class_rename'),
            new LlmCodeMapping('Old\B', 'New\B', 'class_rename'),
        ]);

        $rules  = $this->generator->generate($result, $this->createDocument());
        $config = $this->generator->renderCombinedConfig($rules);

        self::assertStringContainsString('RenameClassRector', $config);
        self::assertStringContainsString("'Old\\\\A'", $config);
        self::assertStringContainsString("'Old\\\\B'", $config);
    }

    #[Test]
    public function renderCombinedConfigReturnsEmptyForSkeletonOnlyRules(): void
    {
        $result = $this->createLlmResult([
            new LlmCodeMapping('SomeHook', 'SomeEvent', 'hook_to_event'),
        ]);

        $rules  = $this->generator->generate($result, $this->createDocument());
        $config = $this->generator->renderCombinedConfig($rules);

        self::assertSame('', $config);
    }

    /**
     * @param list<LlmCodeMapping> $mappings
     */
    private function createLlmResult(
        array $mappings,
        ?LlmRectorAssessment $assessment = null,
    ): LlmAnalysisResult {
        return new LlmAnalysisResult(
            filename: 'Deprecation-12345-Test.rst',
            modelId: 'claude-haiku',
            promptVersion: '1.0',
            score: 2,
            automationGrade: AutomationGrade::Partial,
            summary: 'Test summary',
            reasoning: '',
            migrationSteps: [],
            affectedAreas: [],
            affectedComponents: [],
            codeMappings: $mappings,
            rectorAssessment: $assessment,
            tokensInput: 100,
            tokensOutput: 50,
            durationMs: 500,
            createdAt: '2026-03-09 12:00:00',
        );
    }

    /**
     * @param list<CodeBlock> $codeBlocks
     */
    private function createDocument(array $codeBlocks = []): RstDocument
    {
        return new RstDocument(
            type: DocumentType::Deprecation,
            issueId: 12345,
            title: 'Test deprecation',
            version: '13.0',
            description: 'Test description.',
            impact: null,
            migration: 'Use the new API.',
            codeReferences: [],
            indexTags: [],
            scanStatus: ScanStatus::NotScanned,
            filename: 'Deprecation-12345-Test.rst',
            codeBlocks: $codeBlocks,
        );
    }

    /**
     * @param list<LlmRectorRule> $rules
     *
     * @return list<LlmRectorRule>
     */
    private function filterByType(array $rules, RectorRuleType $type): array
    {
        return array_values(array_filter(
            $rules,
            static fn (LlmRectorRule $r): bool => $r->type === $type,
        ));
    }
}
