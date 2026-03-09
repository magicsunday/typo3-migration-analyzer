# LLM-Analyse-Integration — Implementierungsplan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** LLM-basierte Analyse jedes RST-Dokuments, um konkrete Migrationsschritte abzuleiten, den Automationsgrad präziser zu bestimmen und die Ergebnisse persistent in SQLite zu speichern.

**Architecture:** Ein `LlmClientInterface` abstrahiert verschiedene LLM-Provider (Claude, OpenAI). Der `LlmAnalysisService` orchestriert die Analyse einzelner Dokumente und speichert Ergebnisse via `LlmResultRepository` in SQLite. CLI-Commands ermöglichen Bulk-Analyse. Die Web-UI zeigt gecachte Ergebnisse an und erlaubt Re-Analyse. Eine Konfigurationsseite verwaltet Provider, Modelle und Prompts.

**Tech Stack:** PHP 8.4, Symfony 7.4, Twig, Bootstrap 5.3, SQLite (via PDO), symfony/http-client

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- One class per file, `final readonly` for DTOs, `use function` imports
- English PHPDoc + English inline comments

---

## Übersicht

### Komponenten

```
src/
├── Dto/
│   ├── LlmAnalysisResult.php          # Ergebnis einer LLM-Analyse
│   ├── LlmProvider.php                # Enum: Claude, OpenAi
│   ├── LlmModel.php                   # VO: Provider + Modell-ID + Label + Kosten
│   └── LlmConfiguration.php           # VO: Gewähltes Modell, Prompt, API-Key
├── Llm/
│   ├── LlmClientInterface.php         # Abstraction für LLM-API-Aufrufe
│   ├── ClaudeClient.php               # Anthropic Messages API
│   ├── OpenAiClient.php               # OpenAI Chat Completions API
│   └── LlmClientFactory.php           # Factory: Provider → Client
├── Repository/
│   └── LlmResultRepository.php        # SQLite CRUD für Analyseergebnisse
├── Service/
│   ├── LlmAnalysisService.php         # Orchestrierung: Dokument → Prompt → LLM → Speichern
│   └── LlmConfigurationService.php    # Lädt/speichert LLM-Konfiguration (YAML)
├── Command/
│   ├── LlmAnalyzeCommand.php          # bin/console llm:analyze [--all|filename]
│   └── LlmStatusCommand.php           # bin/console llm:status
└── Controller/
    └── LlmController.php              # Konfig-Seite, Einzel-Re-Analyse, Bulk-Trigger
```

### Datenfluss

```
RST-Dokument
    │
    ▼
LlmAnalysisService::analyze(RstDocument)
    │
    ├─ Prüft LlmResultRepository: Ergebnis vorhanden?
    │   ├─ Ja → Return cached result
    │   └─ Nein → Weiter
    │
    ├─ Baut Prompt aus Template + RstDocument-Daten
    │
    ├─ LlmClientFactory::create(provider) → LlmClientInterface
    │
    ├─ LlmClientInterface::analyze(prompt, document) → JSON
    │
    ├─ Parst JSON → LlmAnalysisResult
    │
    ├─ LlmResultRepository::save(result)
    │
    └─ Return LlmAnalysisResult
```

---

## Task 1: Composer-Dependency + SQLite-Setup

**Files:**
- Modify: `composer.json`
- Create: `migrations/schema.sql`

**Step 1: Dependency hinzufügen**

```bash
docker compose exec phpfpm composer require symfony/http-client
```

**Step 2: SQLite-Schema definieren**

Create `migrations/schema.sql`:

```sql
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
```

**Step 3: SQLite-Pfad in services.yaml konfigurieren**

```yaml
services:
  _defaults:
    bind:
      string $tmpDir: '%kernel.project_dir%/var/tmp'
      string $sqlitePath: '%kernel.project_dir%/var/data/llm_results.db'
```

**Step 4: Commit**

```
Add symfony/http-client and SQLite schema for LLM analysis results
```

---

## Task 2: LLM DTOs

**Files:**
- Create: `src/Dto/LlmProvider.php`
- Create: `src/Dto/LlmModel.php`
- Create: `src/Dto/LlmAnalysisResult.php`
- Tests: `tests/Unit/Dto/LlmAnalysisResultTest.php`

**Step 1: Provider Enum**

```php
// src/Dto/LlmProvider.php
enum LlmProvider: string
{
    case Claude = 'claude';
    case OpenAi = 'openai';
}
```

**Step 2: Model VO**

```php
// src/Dto/LlmModel.php
final readonly class LlmModel
{
    /**
     * @param float $inputCostPerMillion  Cost per million input tokens in USD
     * @param float $outputCostPerMillion Cost per million output tokens in USD
     */
    public function __construct(
        public LlmProvider $provider,
        public string $modelId,
        public string $label,
        public float $inputCostPerMillion,
        public float $outputCostPerMillion,
    ) {
    }

    /**
     * Returns the estimated cost for the given token counts.
     */
    public function estimateCost(int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens / 1_000_000) * $this->inputCostPerMillion
            + ($outputTokens / 1_000_000) * $this->outputCostPerMillion;
    }
}
```

**Step 3: AnalysisResult VO**

```php
// src/Dto/LlmAnalysisResult.php
final readonly class LlmAnalysisResult
{
    /**
     * @param list<string> $migrationSteps  Concrete steps for migration
     * @param list<string> $affectedAreas   e.g. ['PHP', 'Fluid', 'TCA', 'JavaScript']
     */
    public function __construct(
        public string $filename,
        public string $modelId,
        public string $promptVersion,
        public int $score,
        public string $automationGrade,
        public string $summary,
        public array $migrationSteps,
        public array $affectedAreas,
        public int $tokensInput,
        public int $tokensOutput,
        public int $durationMs,
        public string $createdAt,
    ) {
    }
}
```

**Step 4: Tests schreiben und grün machen**

**Step 5: Commit**

```
Add LLM DTOs for provider, model, and analysis result
```

---

## Task 3: LlmResultRepository (SQLite)

**Files:**
- Create: `src/Repository/LlmResultRepository.php`
- Test: `tests/Unit/Repository/LlmResultRepositoryTest.php`

**Step 1: Repository implementieren**

```php
// src/Repository/LlmResultRepository.php
final class LlmResultRepository
{
    private \PDO $pdo;

    public function __construct(string $sqlitePath)
    {
        $directory = dirname($sqlitePath);

        if (!is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $this->pdo = new \PDO('sqlite:' . $sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->initializeSchema();
    }

    /**
     * Find a cached result by filename, model, and prompt version.
     */
    public function find(string $filename, string $modelId, string $promptVersion): ?LlmAnalysisResult
    {
        // SELECT by filename_hash + model_id + prompt_version
        // Return null if not found
    }

    /**
     * Save or update an analysis result.
     */
    public function save(LlmAnalysisResult $result): void
    {
        // INSERT OR REPLACE
    }

    /**
     * Delete a cached result to force re-analysis.
     */
    public function delete(string $filename, string $modelId, string $promptVersion): void
    {
        // DELETE by composite key
    }

    /**
     * Count how many documents have been analyzed.
     */
    public function countAnalyzed(): int
    {
        // SELECT COUNT(DISTINCT filename)
    }

    /**
     * Get all analyzed filenames.
     *
     * @return list<string>
     */
    public function getAnalyzedFilenames(): array
    {
        // SELECT DISTINCT filename
    }

    /**
     * Find result by filename only (latest model/prompt version).
     */
    public function findLatest(string $filename): ?LlmAnalysisResult
    {
        // SELECT ... ORDER BY created_at DESC LIMIT 1
    }

    private function initializeSchema(): void
    {
        // Run CREATE TABLE IF NOT EXISTS
    }

    private static function filenameHash(string $filename): string
    {
        return hash('xxh128', $filename);
    }
}
```

**Key Design Decisions:**
- Schema wird automatisch beim ersten Zugriff erstellt (kein separater Migrations-Schritt)
- `filenameHash` verwendet xxh128 (schnell, kollisionssicher genug)
- `UNIQUE(filename_hash, model_id, prompt_version)` verhindert Duplikate
- `findLatest()` für die UI — zeigt immer das neueste Ergebnis unabhängig von Modell/Prompt

**Step 2: Tests mit In-Memory SQLite**

```php
// Tests nutzen ':memory:' statt File-basierter DB
private function createRepository(): LlmResultRepository
{
    return new LlmResultRepository(':memory:');
}
```

**Step 3: Commit**

```
Add SQLite-backed LlmResultRepository for persistent analysis caching
```

---

## Task 4: LLM-Client-Abstraction

**Files:**
- Create: `src/Llm/LlmClientInterface.php`
- Create: `src/Llm/ClaudeClient.php`
- Create: `src/Llm/OpenAiClient.php`
- Create: `src/Llm/LlmClientFactory.php`
- Create: `src/Llm/LlmResponse.php`
- Tests: Unit-Tests mit gemocktem HttpClient

**Step 1: Interface definieren**

```php
// src/Llm/LlmClientInterface.php
interface LlmClientInterface
{
    /**
     * Send a prompt to the LLM and return the structured response.
     */
    public function analyze(string $systemPrompt, string $userPrompt, string $modelId): LlmResponse;
}
```

```php
// src/Llm/LlmResponse.php
final readonly class LlmResponse
{
    public function __construct(
        public string $content,
        public int $inputTokens,
        public int $outputTokens,
        public int $durationMs,
    ) {
    }
}
```

**Step 2: Claude Client (Anthropic Messages API)**

```php
// src/Llm/ClaudeClient.php
final readonly class ClaudeClient implements LlmClientInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    public function analyze(string $systemPrompt, string $userPrompt, string $modelId): LlmResponse
    {
        $startTime = hrtime(true);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => [
                'model' => $modelId,
                'max_tokens' => 2048,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ],
        ]);

        $data = $response->toArray();
        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new LlmResponse(
            content: $data['content'][0]['text'],
            inputTokens: $data['usage']['input_tokens'],
            outputTokens: $data['usage']['output_tokens'],
            durationMs: $durationMs,
        );
    }
}
```

**Step 3: OpenAI Client (Chat Completions API)**

```php
// src/Llm/OpenAiClient.php
final readonly class OpenAiClient implements LlmClientInterface
{
    private const string API_URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $apiKey,
    ) {
    }

    public function analyze(string $systemPrompt, string $userPrompt, string $modelId): LlmResponse
    {
        // POST to /v1/chat/completions with model, messages[], max_tokens
        // Parse response: choices[0].message.content, usage.prompt_tokens, usage.completion_tokens
    }
}
```

**Step 4: Factory**

```php
// src/Llm/LlmClientFactory.php
final readonly class LlmClientFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function create(LlmProvider $provider, string $apiKey): LlmClientInterface
    {
        return match ($provider) {
            LlmProvider::Claude => new ClaudeClient($this->httpClient, $apiKey),
            LlmProvider::OpenAi => new OpenAiClient($this->httpClient, $apiKey),
        };
    }
}
```

**Step 5: Commit**

```
Add LLM client abstraction with Claude and OpenAI implementations
```

---

## Task 5: LlmConfigurationService

**Files:**
- Create: `src/Service/LlmConfigurationService.php`
- Create: `src/Dto/LlmConfiguration.php`
- Test: `tests/Unit/Service/LlmConfigurationServiceTest.php`

**Step 1: Configuration DTO**

```php
// src/Dto/LlmConfiguration.php
final readonly class LlmConfiguration
{
    public function __construct(
        public LlmProvider $provider,
        public string $modelId,
        public string $apiKey,
        public string $analysisPrompt,
        public string $promptVersion,
    ) {
    }
}
```

**Step 2: YAML-basierter Config Service**

Speichert die Konfiguration in `var/data/llm_config.yaml`. Enthält API-Keys, gewähltes Modell, angepassten Prompt.

```php
// src/Service/LlmConfigurationService.php
final class LlmConfigurationService
{
    private const string CONFIG_FILENAME = 'llm_config.yaml';

    public function __construct(
        private string $dataDir, // %kernel.project_dir%/var/data
    ) {
    }

    public function load(): LlmConfiguration { /* YAML lesen, Defaults */ }
    public function save(LlmConfiguration $config): void { /* YAML schreiben */ }
    public function isConfigured(): bool { /* API-Key vorhanden? */ }

    /**
     * Returns all available models with cost information.
     *
     * @return list<LlmModel>
     */
    public function getAvailableModels(): array
    {
        return [
            // Claude models (sorted by cost)
            new LlmModel(LlmProvider::Claude, 'claude-haiku-4-5-20251001', 'Claude Haiku 4.5', 0.80, 4.00),
            new LlmModel(LlmProvider::Claude, 'claude-sonnet-4-6', 'Claude Sonnet 4.6', 3.00, 15.00),
            new LlmModel(LlmProvider::Claude, 'claude-opus-4-6', 'Claude Opus 4.6', 15.00, 75.00),

            // OpenAI models
            new LlmModel(LlmProvider::OpenAi, 'gpt-4o-mini', 'GPT-4o Mini', 0.15, 0.60),
            new LlmModel(LlmProvider::OpenAi, 'gpt-4o', 'GPT-4o', 2.50, 10.00),
        ];
    }

    public function getDefaultPrompt(): string
    {
        return <<<'PROMPT'
            You are a TYPO3 migration expert. Analyze the following RST deprecation/breaking
            change document and provide a structured assessment.

            Respond in JSON format with these fields:
            - score (1-5): Migration complexity
              1 = trivial rename/move (fully automatable via Rector)
              2 = simple replacement with clear instructions
              3 = moderate changes requiring code review
              4 = complex refactoring (hook→event, TCA restructure)
              5 = architectural change requiring manual redesign
            - automation_grade: "full", "partial", or "manual"
            - summary: One-sentence description of what changed and why
            - migration_steps: Array of concrete, actionable steps to migrate
            - affected_areas: Array of affected areas (e.g. "PHP", "Fluid", "TCA",
              "TypoScript", "JavaScript", "YAML", "Flexform", "ext_localconf",
              "ext_tables", "Services.yaml")

            Consider ALL aspects:
            - Is there a 1:1 replacement? → score 1-2
            - Does the signature change? → score 3
            - Is it a pattern change (hook→event, middleware)? → score 4
            - Is there no replacement at all? → score 5
            - Are non-PHP files affected (Fluid templates, TCA, TypoScript)? → increase score
            - Can Rector handle this automatically? → automation_grade "full"
            PROMPT;
    }
}
```

**Step 3: Default-Prompt-Version**

Die Prompt-Version ist ein Hash des Prompt-Textes — ändert sich der Prompt, werden automatisch neue Analysen getriggert:

```php
public function getPromptVersion(string $prompt): string
{
    return hash('xxh64', $prompt);
}
```

**Step 4: Commit**

```
Add YAML-based LLM configuration service with model catalog
```

---

## Task 6: LlmAnalysisService

**Files:**
- Create: `src/Service/LlmAnalysisService.php`
- Test: `tests/Unit/Service/LlmAnalysisServiceTest.php`

**Step 1: Orchestrierungs-Service**

```php
// src/Service/LlmAnalysisService.php
final class LlmAnalysisService
{
    public function __construct(
        private readonly LlmClientFactory $clientFactory,
        private readonly LlmResultRepository $repository,
        private readonly LlmConfigurationService $configService,
    ) {
    }

    /**
     * Analyze a single document, using cached results if available.
     */
    public function analyze(RstDocument $document, bool $forceReanalyze = false): ?LlmAnalysisResult
    {
        $config = $this->configService->load();

        if (!$this->configService->isConfigured()) {
            return null;
        }

        $promptVersion = $this->configService->getPromptVersion($config->analysisPrompt);

        // Check cache unless forced
        if (!$forceReanalyze) {
            $cached = $this->repository->find($document->filename, $config->modelId, $promptVersion);

            if ($cached instanceof LlmAnalysisResult) {
                return $cached;
            }
        }

        // Build prompt with document context
        $userPrompt = $this->buildUserPrompt($document);

        // Call LLM
        $client = $this->clientFactory->create($config->provider, $config->apiKey);
        $response = $client->analyze($config->analysisPrompt, $userPrompt, $config->modelId);

        // Parse JSON response
        $result = $this->parseResponse($response, $document, $config);

        // Persist
        $this->repository->save($result);

        return $result;
    }

    /**
     * Get cached result without triggering analysis.
     */
    public function getCachedResult(string $filename): ?LlmAnalysisResult
    {
        return $this->repository->findLatest($filename);
    }

    /**
     * Returns analysis progress: how many documents are analyzed vs total.
     *
     * @return array{analyzed: int, total: int, percent: float}
     */
    public function getProgress(int $totalDocuments): array
    {
        $analyzed = $this->repository->countAnalyzed();

        return [
            'analyzed' => $analyzed,
            'total' => $totalDocuments,
            'percent' => $totalDocuments > 0
                ? round($analyzed / $totalDocuments * 100, 1)
                : 0.0,
        ];
    }

    private function buildUserPrompt(RstDocument $document): string
    {
        $parts = [
            "Document: {$document->filename}",
            "Type: {$document->type->value}",
            "Version: {$document->version}",
            "Title: {$document->title}",
            '',
            '## Description',
            $document->description,
        ];

        if ($document->impact !== null && $document->impact !== '') {
            $parts[] = '';
            $parts[] = '## Impact';
            $parts[] = $document->impact;
        }

        if ($document->migration !== null && $document->migration !== '') {
            $parts[] = '';
            $parts[] = '## Migration';
            $parts[] = $document->migration;
        }

        if ($document->codeBlocks !== []) {
            $parts[] = '';
            $parts[] = '## Code Examples';

            foreach ($document->codeBlocks as $block) {
                $label = $block->label !== '' ? " ({$block->label})" : '';
                $parts[] = "```{$block->language}{$label}";
                $parts[] = $block->code;
                $parts[] = '```';
            }
        }

        return implode("\n", $parts);
    }

    private function parseResponse(
        LlmResponse $response,
        RstDocument $document,
        LlmConfiguration $config,
    ): LlmAnalysisResult {
        $data = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        return new LlmAnalysisResult(
            filename: $document->filename,
            modelId: $config->modelId,
            promptVersion: $this->configService->getPromptVersion($config->analysisPrompt),
            score: (int) ($data['score'] ?? 3),
            automationGrade: (string) ($data['automation_grade'] ?? 'manual'),
            summary: (string) ($data['summary'] ?? ''),
            migrationSteps: (array) ($data['migration_steps'] ?? []),
            affectedAreas: (array) ($data['affected_areas'] ?? []),
            tokensInput: $response->inputTokens,
            tokensOutput: $response->outputTokens,
            durationMs: $response->durationMs,
            createdAt: date('Y-m-d H:i:s'),
        );
    }
}
```

**Step 2: Commit**

```
Add LlmAnalysisService for orchestrating document analysis
```

---

## Task 7: CLI Commands

**Files:**
- Create: `src/Command/LlmAnalyzeCommand.php`
- Create: `src/Command/LlmStatusCommand.php`
- Test: Functional tests

**Step 1: Analyze Command**

```bash
# Alle Dokumente im aktuellen Versionsbereich analysieren
bin/console llm:analyze --all

# Einzelnes Dokument analysieren
bin/console llm:analyze Breaking-59659-DeprecatedCodeRemovalInBackendSysext.rst

# Re-Analyse erzwingen
bin/console llm:analyze --all --force

# Nur nicht-analysierte Dokumente
bin/console llm:analyze --missing
```

```php
// src/Command/LlmAnalyzeCommand.php
#[AsCommand(name: 'llm:analyze', description: 'Analyze RST documents using configured LLM')]
final class LlmAnalyzeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('filename', InputArgument::OPTIONAL, 'Single RST filename to analyze')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Analyze all documents')
            ->addOption('missing', null, InputOption::VALUE_NONE, 'Only analyze documents without cached results')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force re-analysis even if cached');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Check LLM configuration
        // 2. Resolve document(s) to analyze
        // 3. Progress bar: foreach document → analyze with rate limiting
        // 4. Summary: analyzed count, skipped count, errors, total cost
    }
}
```

Rate Limiting: 1 Sekunde Pause zwischen API-Calls um Throttling zu vermeiden.

**Step 2: Status Command**

```bash
bin/console llm:status
# Output:
# LLM Provider: Claude (claude-haiku-4-5-20251001)
# Analyzed: 342 / 3659 documents (9.3%)
# Total tokens: 1.2M input, 340K output
# Estimated cost: $1.34
```

**Step 3: Commit**

```
Add CLI commands for LLM analysis and status reporting
```

---

## Task 8: Konfigurationsseite (Web-UI)

**Files:**
- Create: `src/Controller/LlmController.php`
- Create: `templates/llm/config.html.twig`
- Modify: `templates/base.html.twig` (Sidebar-Link)

**Step 1: Controller**

```php
// src/Controller/LlmController.php
final class LlmController extends AbstractController
{
    #[Route('/llm/config', name: 'llm_config')]
    public function config(Request $request, LlmConfigurationService $configService): Response
    {
        // GET: Formular anzeigen mit aktueller Konfiguration
        // POST: Konfiguration speichern, Redirect zurück
    }

    #[Route('/llm/analyze/{filename}', name: 'llm_analyze_single', methods: ['POST'])]
    public function analyzeSingle(
        string $filename,
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
    ): JsonResponse {
        // AJAX: Einzelnes Dokument analysieren, JSON zurückgeben
    }

    #[Route('/llm/analyze-bulk', name: 'llm_analyze_bulk', methods: ['POST'])]
    public function analyzeBulk(
        Request $request,
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
    ): JsonResponse {
        // AJAX: Nächstes nicht-analysiertes Dokument analysieren
        // Returns: {filename, result, progress: {analyzed, total, percent}}
        // Frontend ruft wiederholt auf bis progress.percent === 100
    }

    #[Route('/llm/progress', name: 'llm_progress', methods: ['GET'])]
    public function progress(
        LlmAnalysisService $analysisService,
        DocumentService $documentService,
    ): JsonResponse {
        // Returns: {analyzed, total, percent}
    }
}
```

**Step 2: Config-Template**

```twig
{# templates/llm/config.html.twig #}
{# Layout:
   - Provider-Auswahl (Radio: Claude / OpenAI)
   - API-Key Eingabefeld (password, mit Toggle-Sichtbarkeit)
   - Modell-Auswahl (Dropdown, gefiltert nach Provider)
     - Jedes Modell zeigt: Label + Kosten pro 1M Tokens
   - Prompt-Textarea mit Default-Vorbelegung + Reset-Button
   - Speichern-Button
   - Status-Card: Analysiert X von Y (Z%), Geschätzte Kosten
   - Bulk-Analyse-Button mit Fortschrittsbalken
#}
```

**Step 3: Sidebar-Link**

In `templates/base.html.twig`, neuer Nav-Eintrag:

```twig
<li>
    <a href="{{ path('llm_config') }}" class="nav-link text-white {{ app.request.attributes.get('_route') starts with 'llm' ? 'active bg-typo3' : '' }}">
        <i class="bi bi-robot me-2"></i><span class="sidebar-label">LLM-Analyse</span>
    </a>
</li>
```

**Step 4: Commit**

```
Add LLM configuration page with provider selection and bulk analysis
```

---

## Task 9: Detail-Seite Integration

**Files:**
- Modify: `src/Controller/DeprecationController.php`
- Modify: `templates/deprecation/detail.html.twig`
- Create: `public/js/llm-analysis.js`

**Step 1: Controller erweitern**

In `DeprecationController::detail()` das LLM-Ergebnis laden:

```php
public function detail(
    string $filename,
    DocumentService $documentService,
    MigrationMappingExtractor $extractor,
    ComplexityScorer $complexityScorer,
    LlmAnalysisService $llmService,
    VersionRangeProvider $versionRangeProvider,
): Response {
    // ... existing code ...

    return $this->render('deprecation/detail.html.twig', [
        'doc'           => $doc,
        'mappings'      => $extractor->extract($doc->migration, $doc->description),
        'complexity'    => $complexityScorer->score($doc),
        'llmResult'     => $llmService->getCachedResult($filename),
        'versionRange'  => $documentService->getVersionRange(),
        'majorVersions' => $versionRangeProvider->getAvailableMajorVersions(),
    ]);
}
```

**Step 2: Sidebar-Card für LLM-Ergebnis**

```twig
{# In der Sidebar (col-lg-4), nach Code-Referenzen #}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0">
            <i class="bi bi-robot me-1"></i>LLM-Analyse
        </h6>
        <button id="llm-reanalyze" class="btn btn-sm btn-outline-primary"
                data-filename="{{ doc.filename }}"
                data-url="{{ path('llm_analyze_single', {filename: doc.filename}) }}">
            <i class="bi bi-arrow-clockwise"></i>
        </button>
    </div>
    <div class="card-body" id="llm-result">
        {% if llmResult %}
            {# Score + Automation Grade badges #}
            {# Summary #}
            {# Migration Steps (numbered list) #}
            {# Affected Areas (badge list) #}
            {# Meta: Model, Tokens, Duration #}
        {% else %}
            <p class="text-muted mb-2">Noch nicht analysiert.</p>
            <button class="btn btn-sm btn-typo3" id="llm-analyze-now"
                    data-url="{{ path('llm_analyze_single', {filename: doc.filename}) }}">
                <i class="bi bi-play me-1"></i>Jetzt analysieren
            </button>
        {% endif %}
    </div>
</div>
```

**Step 3: JavaScript für Re-Analyse**

```javascript
// public/js/llm-analysis.js
// Handles:
// - "Jetzt analysieren" button click → POST to llm_analyze_single
// - "Re-Analyse" button click → POST with force flag
// - Updates card content with response data
// - Shows spinner during analysis
```

**Step 4: Commit**

```
Show LLM analysis results on deprecation detail page
```

---

## Task 10: Bulk-Analyse mit Fortschrittsanzeige

**Files:**
- Modify: `templates/llm/config.html.twig`
- Create: `public/js/llm-bulk.js`

**Step 1: Fortschritts-UI auf der Config-Seite**

```twig
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h6>Massenanalyse</h6>
        <div class="progress mb-3" style="height: 24px;">
            <div id="bulk-progress" class="progress-bar bg-typo3" style="width: 0%">0%</div>
        </div>
        <div class="d-flex justify-content-between align-items-center">
            <span id="bulk-status" class="text-muted small">—</span>
            <button id="bulk-start" class="btn btn-typo3">
                <i class="bi bi-play-fill me-1"></i>Analyse starten
            </button>
        </div>
    </div>
</div>
```

**Step 2: JavaScript Bulk-Analyse**

```javascript
// public/js/llm-bulk.js
// 1. Click "Analyse starten" → POST to llm_analyze_bulk
// 2. Response contains: {filename, result, progress}
// 3. Update progress bar + status text
// 4. If progress.percent < 100 → setTimeout → next call (rate limiting)
// 5. On page load: GET llm_progress → restore progress bar state
// 6. Stop-Button to interrupt (just stop calling)
```

**Wichtig:** Kein WebSocket oder SSE nötig — sequentielle AJAX-Calls mit 1s Delay reichen. Der Fortschritt persists in SQLite, also zeigt ein Seitenneuladen den aktuellen Stand.

**Step 3: Commit**

```
Add bulk analysis with progress tracking on LLM configuration page
```

---

## Task 11: Score-Integration

**Files:**
- Modify: `src/Analyzer/ComplexityScorer.php`

**Step 1: LLM-Score als vorrangige Quelle**

Wenn ein LLM-Ergebnis vorliegt, dessen Score verwenden statt der Heuristik:

```php
public function __construct(
    private readonly MigrationMappingExtractor $extractor,
    private readonly LlmResultRepository $repository,
) {
}

public function score(RstDocument $document): ComplexityScore
{
    // Check for LLM result first
    $llmResult = $this->repository->findLatest($document->filename);

    if ($llmResult instanceof LlmAnalysisResult) {
        return new ComplexityScore(
            score: $llmResult->score,
            reason: $llmResult->summary,
            automatable: $llmResult->automationGrade === 'full',
        );
    }

    // Fallback to heuristic scoring
    // ... existing rules ...
}
```

**Step 2: Commit**

```
Use LLM analysis score when available, fall back to heuristic scoring
```

---

## Task 12: Tests, Code-Review, Cleanup

**Step 1:** Vollständige Test-Suite für alle neuen Klassen
**Step 2:** `composer ci:cgl && composer ci:rector`
**Step 3:** `composer ci:test` — alle Tests grün, keine Notices
**Step 4:** PHPStan max level prüfen
**Step 5:** Code-Review aller neuen Dateien
**Step 6:** CLAUDE.md Roadmap aktualisieren

**Status:** ✅ Tasks 1–11 implementiert und getestet (314 Tests grün)

---

## Kostenübersicht (geschätzt)

| Modell | Alle 3.659 Dokumente | Pro Dokument |
|--------|---------------------|-------------|
| Claude Haiku 4.5 | ~$13 | ~$0.004 |
| Claude Sonnet 4.6 | ~$49 | ~$0.013 |
| GPT-4o Mini | ~$2 | ~$0.0006 |
| GPT-4o | ~$35 | ~$0.010 |

Annahme: ~1.500 Input-Tokens + ~500 Output-Tokens pro Dokument.

---

## Sicherheitsaspekte

1. **API-Keys:** In `var/data/llm_config.yaml` gespeichert, Verzeichnis via `.gitignore` ausgeschlossen
2. **SQLite-DB:** In `var/data/`, nicht öffentlich erreichbar
3. **Input-Validation:** Filename-Parameter auf RST-Pattern beschränkt (bestehende Route-Constraints)
4. **Rate Limiting:** 1s Pause zwischen API-Calls im CLI, sequentiell in der UI
5. **Error Handling:** LLM-Fehler (Rate Limit, Auth, Parse) graceful behandeln, nicht den gesamten Bulk-Run abbrechen

---

## Zusammenfassung

| Task | Komponente | Dateien |
|------|-----------|--------|
| 1 | Composer + SQLite Schema | `composer.json`, `migrations/schema.sql` |
| 2 | LLM DTOs | `src/Dto/LlmProvider.php`, `LlmModel.php`, `LlmAnalysisResult.php` |
| 3 | SQLite Repository | `src/Repository/LlmResultRepository.php` |
| 4 | LLM Client Abstraction | `src/Llm/LlmClientInterface.php`, `ClaudeClient.php`, `OpenAiClient.php` |
| 5 | Configuration Service | `src/Service/LlmConfigurationService.php` |
| 6 | Analysis Service | `src/Service/LlmAnalysisService.php` |
| 7 | CLI Commands | `src/Command/LlmAnalyzeCommand.php`, `LlmStatusCommand.php` |
| 8 | Config-Seite | `src/Controller/LlmController.php`, `templates/llm/config.html.twig` |
| 9 | Detail-Integration | Controller + Template + `llm-analysis.js` |
| 10 | Bulk-Analyse UI | Template + `llm-bulk.js` |
| 11 | Score-Integration | `ComplexityScorer.php` |
| 12 | Tests + Review | Alle Dateien |

---

## Implementierungsnotizen

Die folgenden Anpassungen und Erkenntnisse wurden während der Implementierung dokumentiert.

### JSON-Zuverlässigkeit (umgesetzt)

LLM-APIs liefern nicht immer valides JSON. Drei Maßnahmen wurden implementiert:

1. **Claude Assistant Prefill:** `{"role": "assistant", "content": "{"}` erzwingt, dass die Antwort mit `{` beginnt. Der Client prependet `{` zum Response-Text.
2. **OpenAI Response Format:** `'response_format' => ['type' => 'json_object']` garantiert valides JSON.
3. **Markdown Fence Stripping:** `ClaudeClient::stripMarkdownFences()` entfernt `\`\`\`json` und `\`\`\`` Wrapper, falls das Modell die Antwort trotzdem in Code-Fences einpackt.

### HTTP Client Timeout (umgesetzt)

Beide Clients verwenden 60 Sekunden Timeout für LLM API-Calls (`'timeout' => 60`).

### Aggregate Token Queries (umgesetzt)

`LlmResultRepository::getTotalTokens()` liefert `SUM(tokens_input)` und `SUM(tokens_output)` über alle Analysen. Wird von `LlmStatusCommand` und `LlmController` verwendet.

### AutomationGrade als Enum (umgesetzt)

`LlmAnalysisResult::$automationGrade` verwendet den bestehenden `AutomationGrade`-Enum statt `string`. Der Plan hatte `string` vorgesehen, aber der Enum war bereits vorhanden und passt semantisch besser.

### Automatable-Mapping (Bug-Fix nach Code-Review)

`ComplexityScorer` mappt `AutomationGrade` → `bool automatable` mit:
```php
automatable: $llmResult->automationGrade !== AutomationGrade::Manual,
```
Nicht `=== 'full'`, weil `Partial` sonst als nicht-automatisierbar behandelt würde — inkonsistent mit dem Heuristik-Pfad, der Score 1–2 als `automatable: true` markiert.

### migrations/schema.sql (Referenz-Datei)

`migrations/schema.sql` ist eine Referenz-Datei für Dokumentationszwecke. Das Schema wird automatisch von `LlmResultRepository::initializeSchema()` beim ersten Zugriff erstellt. Kein separater Migrations-Schritt nötig.

### PHPUnit + final readonly classes

PHPUnit kann `final readonly` Klassen nicht stubben. Tests für `LlmAnalysisService` verwenden deshalb echte Instanzen (mit `LlmConfigurationService` im Temp-Verzeichnis, `LlmClientFactory` mit Symfonys `MockHttpClient`).

---

## Offene Verbesserungen (noch nicht implementiert)

### Retry/Backoff für API-Fehler

Bei 429 (Rate Limit) oder 5xx sollten die Clients automatisch retry mit exponentiellem Backoff versuchen. Aktuell bricht ein Fehler die Analyse ab.

### Request-ID und Logging

Für Debugging und Nachvollziehbarkeit wäre ein optionales Logging der API-Aufrufe sinnvoll (Request-ID, Dauer, Token-Verbrauch, Fehler).

### Bulk-Analyse Strategie

Die Bulk-Analyse analysiert aktuell das erste nicht-analysierte Dokument. Optimierungen:
- Priorisierung nach Score 3–4 (wo LLM den größten Mehrwert bringt)
- Parallelisierung (aktuell sequentiell wegen Rate Limiting)
