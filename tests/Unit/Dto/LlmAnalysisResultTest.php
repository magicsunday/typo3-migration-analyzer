<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\AutomationGrade;
use App\Dto\LlmAnalysisResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LlmAnalysisResult DTO.
 */
#[CoversClass(LlmAnalysisResult::class)]
final class LlmAnalysisResultTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $result = new LlmAnalysisResult(
            filename: 'Deprecation-98765-SomeFeature.rst',
            modelId: 'claude-haiku-4-5-20251001',
            promptVersion: 'abc123',
            score: 3,
            automationGrade: AutomationGrade::Partial,
            summary: 'The method was deprecated in favor of a new API.',
            reasoning: '',
            migrationSteps: [
                'Replace OldClass::method() with NewClass::method()',
                'Update the method signature',
            ],
            affectedAreas: ['PHP', 'TCA'],
            affectedComponents: [],
            codeMappings: [],
            rectorAssessment: null,
            tokensInput: 1500,
            tokensOutput: 500,
            durationMs: 1200,
            createdAt: '2026-03-09 12:00:00',
        );

        self::assertSame('Deprecation-98765-SomeFeature.rst', $result->filename);
        self::assertSame('claude-haiku-4-5-20251001', $result->modelId);
        self::assertSame('abc123', $result->promptVersion);
        self::assertSame(3, $result->score);
        self::assertSame(AutomationGrade::Partial, $result->automationGrade);
        self::assertSame('The method was deprecated in favor of a new API.', $result->summary);
        self::assertCount(2, $result->migrationSteps);
        self::assertSame('Replace OldClass::method() with NewClass::method()', $result->migrationSteps[0]);
        self::assertSame(['PHP', 'TCA'], $result->affectedAreas);
        self::assertSame(1500, $result->tokensInput);
        self::assertSame(500, $result->tokensOutput);
        self::assertSame(1200, $result->durationMs);
        self::assertSame('2026-03-09 12:00:00', $result->createdAt);
    }

    #[Test]
    public function constructionWithFullAutomation(): void
    {
        $result = new LlmAnalysisResult(
            filename: 'Deprecation-12345-SimpleRename.rst',
            modelId: 'gpt-4o-mini',
            promptVersion: 'def456',
            score: 1,
            automationGrade: AutomationGrade::Full,
            summary: 'Simple class rename.',
            reasoning: '',
            migrationSteps: ['Rename OldClass to NewClass'],
            affectedAreas: ['PHP'],
            affectedComponents: [],
            codeMappings: [],
            rectorAssessment: null,
            tokensInput: 800,
            tokensOutput: 200,
            durationMs: 500,
            createdAt: '2026-03-09 12:00:00',
        );

        self::assertSame(1, $result->score);
        self::assertSame(AutomationGrade::Full, $result->automationGrade);
    }

    #[Test]
    public function constructionWithManualGrade(): void
    {
        $result = new LlmAnalysisResult(
            filename: 'Breaking-99999-ArchitectureChange.rst',
            modelId: 'claude-opus-4-6',
            promptVersion: 'ghi789',
            score: 5,
            automationGrade: AutomationGrade::Manual,
            summary: 'Complete architecture overhaul.',
            reasoning: '',
            migrationSteps: ['Redesign the component from scratch'],
            affectedAreas: ['PHP', 'Fluid', 'JavaScript', 'TCA'],
            affectedComponents: [],
            codeMappings: [],
            rectorAssessment: null,
            tokensInput: 3000,
            tokensOutput: 1000,
            durationMs: 5000,
            createdAt: '2026-03-09 12:00:00',
        );

        self::assertSame(5, $result->score);
        self::assertSame(AutomationGrade::Manual, $result->automationGrade);
        self::assertCount(4, $result->affectedAreas);
    }
}
