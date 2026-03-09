CREATE TABLE IF NOT EXISTS llm_analysis_results (
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
    raw_response TEXT NOT NULL,
    tokens_input INTEGER NOT NULL DEFAULT 0,
    tokens_output INTEGER NOT NULL DEFAULT 0,
    duration_ms INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(filename_hash, model_id, prompt_version)
);

CREATE INDEX IF NOT EXISTS idx_filename_hash ON llm_analysis_results(filename_hash);
CREATE INDEX IF NOT EXISTS idx_model_id ON llm_analysis_results(model_id);
