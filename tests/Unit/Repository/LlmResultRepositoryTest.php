<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Dto\AutomationGrade;
use App\Dto\LlmAnalysisResult;
use App\Dto\LlmCodeMapping;
use App\Dto\LlmRectorAssessment;
use App\Repository\LlmResultRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LlmResultRepository using in-memory SQLite.
 */
#[CoversClass(LlmResultRepository::class)]
final class LlmResultRepositoryTest extends TestCase
{
    #[Test]
    public function findReturnsNullWhenEmpty(): void
    {
        $repository = $this->createRepository();

        self::assertNull($repository->find('nonexistent.rst', 'model', 'v1'));
    }

    #[Test]
    public function saveAndFindRoundTrip(): void
    {
        $repository = $this->createRepository();
        $result     = $this->createResult();

        $repository->save($result);

        $found = $repository->find($result->filename, $result->modelId, $result->promptVersion);

        self::assertNotNull($found);
        self::assertSame($result->filename, $found->filename);
        self::assertSame($result->modelId, $found->modelId);
        self::assertSame($result->promptVersion, $found->promptVersion);
        self::assertSame($result->score, $found->score);
        self::assertSame($result->automationGrade, $found->automationGrade);
        self::assertSame($result->summary, $found->summary);
        self::assertSame($result->migrationSteps, $found->migrationSteps);
        self::assertSame($result->reasoning, $found->reasoning);
        self::assertSame($result->affectedAreas, $found->affectedAreas);
        self::assertSame($result->affectedComponents, $found->affectedComponents);
        self::assertSame($result->tokensInput, $found->tokensInput);
        self::assertSame($result->tokensOutput, $found->tokensOutput);
        self::assertSame($result->durationMs, $found->durationMs);
    }

    #[Test]
    public function saveAndFindRoundTripWithCodeMappingsAndRectorAssessment(): void
    {
        $repository = $this->createRepository();

        $result = new LlmAnalysisResult(
            filename: 'Deprecation-12345-Rename.rst',
            modelId: 'claude-haiku-4-5-20251001',
            promptVersion: 'abc123',
            score: 1,
            automationGrade: AutomationGrade::Full,
            summary: 'Simple class rename.',
            reasoning: 'Direct 1:1 class rename with no signature changes.',
            migrationSteps: ['Rename the class'],
            affectedAreas: ['PHP'],
            affectedComponents: ['Core API'],
            codeMappings: [
                new LlmCodeMapping('TYPO3\\CMS\\Core\\OldClass', 'TYPO3\\CMS\\Core\\NewClass', 'class_rename'),
                new LlmCodeMapping('OldClass::method', null, 'method_removal'),
            ],
            rectorAssessment: new LlmRectorAssessment(
                feasible: true,
                ruleType: 'RenameClassRector',
                notes: 'Straightforward 1:1 rename.',
            ),
            tokensInput: 500,
            tokensOutput: 200,
            durationMs: 300,
            createdAt: '2026-03-09 12:00:00',
        );

        $repository->save($result);
        $found = $repository->find($result->filename, $result->modelId, $result->promptVersion);

        self::assertNotNull($found);
        self::assertCount(2, $found->codeMappings);
        self::assertSame('TYPO3\\CMS\\Core\\OldClass', $found->codeMappings[0]->old);
        self::assertSame('TYPO3\\CMS\\Core\\NewClass', $found->codeMappings[0]->new);
        self::assertSame('class_rename', $found->codeMappings[0]->type);
        self::assertSame('OldClass::method', $found->codeMappings[1]->old);
        self::assertNull($found->codeMappings[1]->new);
        self::assertSame('method_removal', $found->codeMappings[1]->type);

        self::assertSame('Direct 1:1 class rename with no signature changes.', $found->reasoning);
        self::assertSame(['Core API'], $found->affectedComponents);

        self::assertNotNull($found->rectorAssessment);
        self::assertTrue($found->rectorAssessment->feasible);
        self::assertSame('RenameClassRector', $found->rectorAssessment->ruleType);
        self::assertSame('Straightforward 1:1 rename.', $found->rectorAssessment->notes);
    }

    #[Test]
    public function saveReplacesExistingResult(): void
    {
        $repository = $this->createRepository();

        $original = $this->createResult(score: 3, summary: 'Original analysis');
        $repository->save($original);

        $updated = $this->createResult(score: 4, summary: 'Updated analysis');
        $repository->save($updated);

        $found = $repository->find($original->filename, $original->modelId, $original->promptVersion);

        self::assertNotNull($found);
        self::assertSame(4, $found->score);
        self::assertSame('Updated analysis', $found->summary);
    }

    #[Test]
    public function findLatestReturnsNewestResult(): void
    {
        $repository = $this->createRepository();

        $older = $this->createResult(
            promptVersion: 'v1',
            summary: 'Older analysis',
            createdAt: '2026-03-01 10:00:00',
        );

        $newer = $this->createResult(
            promptVersion: 'v2',
            summary: 'Newer analysis',
            createdAt: '2026-03-09 12:00:00',
        );

        $repository->save($older);
        $repository->save($newer);

        $found = $repository->findLatest('Deprecation-98765-SomeFeature.rst');

        self::assertNotNull($found);
        self::assertSame('Newer analysis', $found->summary);
    }

    #[Test]
    public function findLatestReturnsNullWhenNotFound(): void
    {
        $repository = $this->createRepository();

        self::assertNull($repository->findLatest('nonexistent.rst'));
    }

    #[Test]
    public function deleteRemovesResult(): void
    {
        $repository = $this->createRepository();
        $result     = $this->createResult();

        $repository->save($result);
        self::assertNotNull($repository->find($result->filename, $result->modelId, $result->promptVersion));

        $repository->delete($result->filename, $result->modelId, $result->promptVersion);
        self::assertNull($repository->find($result->filename, $result->modelId, $result->promptVersion));
    }

    #[Test]
    public function countAnalyzedReturnsDistinctFilenames(): void
    {
        $repository = $this->createRepository();

        self::assertSame(0, $repository->countAnalyzed());

        $repository->save($this->createResult(filename: 'file1.rst'));
        $repository->save($this->createResult(filename: 'file2.rst'));

        // Same filename but different model — should count as 1
        $repository->save($this->createResult(filename: 'file1.rst', modelId: 'gpt-4o'));

        self::assertSame(2, $repository->countAnalyzed());
    }

    #[Test]
    public function getAnalyzedFilenamesReturnsDistinctSortedList(): void
    {
        $repository = $this->createRepository();

        $repository->save($this->createResult(filename: 'Breaking-999-B.rst'));
        $repository->save($this->createResult(filename: 'Deprecation-111-A.rst'));
        $repository->save($this->createResult(filename: 'Breaking-999-B.rst', modelId: 'gpt-4o'));

        $filenames = $repository->getAnalyzedFilenames();

        self::assertSame(['Breaking-999-B.rst', 'Deprecation-111-A.rst'], $filenames);
    }

    /**
     * Creates an in-memory repository for testing.
     */
    private function createRepository(): LlmResultRepository
    {
        return new LlmResultRepository(':memory:');
    }

    /**
     * Creates a test analysis result.
     */
    private function createResult(
        string $filename = 'Deprecation-98765-SomeFeature.rst',
        string $modelId = 'claude-haiku-4-5-20251001',
        string $promptVersion = 'abc123',
        int $score = 3,
        string $summary = 'The method was deprecated.',
        string $createdAt = '2026-03-09 12:00:00',
    ): LlmAnalysisResult {
        return new LlmAnalysisResult(
            filename: $filename,
            modelId: $modelId,
            promptVersion: $promptVersion,
            score: $score,
            automationGrade: AutomationGrade::Partial,
            summary: $summary,
            reasoning: '',
            migrationSteps: ['Replace old with new'],
            affectedAreas: ['PHP'],
            affectedComponents: [],
            codeMappings: [],
            rectorAssessment: null,
            tokensInput: 1500,
            tokensOutput: 500,
            durationMs: 1200,
            createdAt: $createdAt,
        );
    }
}
