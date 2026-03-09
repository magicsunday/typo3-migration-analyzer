<?php

/**
 * This file is part of the package magicsunday/typo3-migration-analyzer.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace App\Repository;

use App\Dto\AutomationGrade;
use App\Dto\LlmAnalysisResult;
use PDO;
use RuntimeException;

use function dirname;
use function hash;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function sprintf;

/**
 * SQLite-backed repository for persisting LLM analysis results.
 */
final readonly class LlmResultRepository
{
    private PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        if ($sqlitePath !== ':memory:') {
            $directory = dirname($sqlitePath);

            if (!is_dir($directory) && !mkdir($directory, 0o755, true)) {
                throw new RuntimeException(sprintf('Failed to create directory: %s', $directory));
            }
        }

        $this->pdo = new PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initializeSchema();
    }

    /**
     * Find a cached result by filename, model, and prompt version.
     */
    public function find(string $filename, string $modelId, string $promptVersion): ?LlmAnalysisResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM llm_analysis_results
             WHERE filename_hash = :hash AND model_id = :model AND prompt_version = :prompt
             LIMIT 1',
        );

        $stmt->execute([
            'hash'   => $this->filenameHash($filename),
            'model'  => $modelId,
            'prompt' => $promptVersion,
        ]);

        /** @var array<string, string>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Find the latest result for a filename regardless of model or prompt version.
     */
    public function findLatest(string $filename): ?LlmAnalysisResult
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM llm_analysis_results
             WHERE filename_hash = :hash
             ORDER BY created_at DESC
             LIMIT 1',
        );

        $stmt->execute(['hash' => $this->filenameHash($filename)]);

        /** @var array<string, string>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Save or update an analysis result.
     */
    public function save(LlmAnalysisResult $result): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO llm_analysis_results
             (filename_hash, filename, model_id, prompt_version, score, automation_grade,
              summary, migration_steps, affected_areas,
              tokens_input, tokens_output, duration_ms, created_at)
             VALUES (:hash, :filename, :model, :prompt, :score, :grade,
                     :summary, :steps, :areas,
                     :input, :output, :duration, :created)',
        );

        $stmt->execute([
            'hash'     => $this->filenameHash($result->filename),
            'filename' => $result->filename,
            'model'    => $result->modelId,
            'prompt'   => $result->promptVersion,
            'score'    => $result->score,
            'grade'    => $result->automationGrade->value,
            'summary'  => $result->summary,
            'steps'    => json_encode($result->migrationSteps, JSON_THROW_ON_ERROR),
            'areas'    => json_encode($result->affectedAreas, JSON_THROW_ON_ERROR),
            'input'    => $result->tokensInput,
            'output'   => $result->tokensOutput,
            'duration' => $result->durationMs,
            'created'  => $result->createdAt,
        ]);
    }

    /**
     * Delete a cached result to force re-analysis.
     */
    public function delete(string $filename, string $modelId, string $promptVersion): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM llm_analysis_results
             WHERE filename_hash = :hash AND model_id = :model AND prompt_version = :prompt',
        );

        $stmt->execute([
            'hash'   => $this->filenameHash($filename),
            'model'  => $modelId,
            'prompt' => $promptVersion,
        ]);
    }

    /**
     * Count how many distinct documents have been analyzed.
     */
    public function countAnalyzed(): int
    {
        $result = $this->pdo->query('SELECT COUNT(DISTINCT filename) FROM llm_analysis_results');

        if ($result === false) {
            return 0;
        }

        return (int) $result->fetchColumn();
    }

    /**
     * Get total token usage across all analyzed documents.
     *
     * @return array{input: int, output: int}
     */
    public function getTotalTokens(): array
    {
        $result = $this->pdo->query(
            'SELECT COALESCE(SUM(tokens_input), 0) AS input, COALESCE(SUM(tokens_output), 0) AS output
             FROM llm_analysis_results',
        );

        if ($result === false) {
            return ['input' => 0, 'output' => 0];
        }

        /** @var array{input: string, output: string} $row */
        $row = $result->fetch(PDO::FETCH_ASSOC);

        return [
            'input'  => (int) $row['input'],
            'output' => (int) $row['output'],
        ];
    }

    /**
     * Get all analyzed filenames.
     *
     * @return list<string>
     */
    public function getAnalyzedFilenames(): array
    {
        $result = $this->pdo->query('SELECT DISTINCT filename FROM llm_analysis_results ORDER BY filename');

        if ($result === false) {
            return [];
        }

        /** @var list<string> */
        return $result->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Initialize the database schema.
     */
    private function initializeSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS llm_analysis_results (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename_hash TEXT NOT NULL,
                filename TEXT NOT NULL,
                model_id TEXT NOT NULL,
                prompt_version TEXT NOT NULL,
                score INTEGER NOT NULL,
                automation_grade TEXT NOT NULL,
                summary TEXT NOT NULL,
                migration_steps TEXT NOT NULL,
                affected_areas TEXT NOT NULL,
                tokens_input INTEGER NOT NULL DEFAULT 0,
                tokens_output INTEGER NOT NULL DEFAULT 0,
                duration_ms INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE(filename_hash, model_id, prompt_version)
            )',
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_filename_hash ON llm_analysis_results(filename_hash)',
        );

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_model_id ON llm_analysis_results(model_id)',
        );

        $this->migrateSchema();
    }

    /**
     * Apply schema migrations for backwards compatibility with older database files.
     */
    private function migrateSchema(): void
    {
        // Drop the raw_response column that was removed in a previous refactoring
        $result = $this->pdo->query('PRAGMA table_info(llm_analysis_results)');

        if ($result === false) {
            return;
        }

        /** @var list<array{name: string}> $columns */
        $columns = $result->fetchAll(PDO::FETCH_ASSOC);

        foreach ($columns as $column) {
            if ($column['name'] === 'raw_response') {
                $this->pdo->exec('ALTER TABLE llm_analysis_results DROP COLUMN raw_response');

                break;
            }
        }
    }

    /**
     * Hydrate a database row into an LlmAnalysisResult.
     *
     * @param array<string, string> $row
     */
    private function hydrate(array $row): LlmAnalysisResult
    {
        /** @var list<string> $migrationSteps */
        $migrationSteps = json_decode($row['migration_steps'], true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> $affectedAreas */
        $affectedAreas = json_decode($row['affected_areas'], true, 512, JSON_THROW_ON_ERROR);

        return new LlmAnalysisResult(
            filename: $row['filename'],
            modelId: $row['model_id'],
            promptVersion: $row['prompt_version'],
            score: (int) $row['score'],
            automationGrade: AutomationGrade::from($row['automation_grade']),
            summary: $row['summary'],
            migrationSteps: $migrationSteps,
            affectedAreas: $affectedAreas,
            tokensInput: (int) $row['tokens_input'],
            tokensOutput: (int) $row['tokens_output'],
            durationMs: (int) $row['duration_ms'],
            createdAt: $row['created_at'],
        );
    }

    /**
     * Generate a hash for the filename to use as a lookup key.
     */
    private function filenameHash(string $filename): string
    {
        return hash('xxh128', $filename);
    }
}
