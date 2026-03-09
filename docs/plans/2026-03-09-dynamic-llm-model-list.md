# Dynamic LLM Model List — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace the hardcoded model list with dynamic API-based model discovery, filtered to chat-capable models, with a local pricing map and graceful fallback.

**Architecture:** A `LlmModelProviderInterface` abstracts model listing per provider. `ClaudeModelProvider` and `OpenAiModelProvider` call their respective `/v1/models` APIs. Results are cached for 1 hour via Symfony Cache. A local `PRICING_MAP` provides cost data for known models; unknown models show "Kosten unbekannt". If the API is unreachable or no API key is set, the pricing map keys serve as a static fallback list.

**Tech Stack:** PHP 8.4, Symfony 7.4, symfony/http-client, symfony/cache

**Conventions:**
- `composer ci:cgl` and `composer ci:rector` before every commit
- `composer ci:test` must be green before every commit
- Code review after every commit, fix findings immediately
- Commit messages in English, no prefix convention, no Co-Authored-By
- One class per file, `final readonly` for DTOs/clients, `use function` imports
- English PHPDoc + English inline comments

---

## Übersicht

### API-Endpunkte

| Provider | Endpoint | Auth | Pagination | Besonderheiten |
|----------|----------|------|-----------|----------------|
| Anthropic | `GET /v1/models` | `x-api-key` + `anthropic-version` | `limit`, `after_id` | Hat `display_name` |
| OpenAI | `GET /v1/models` | `Bearer` token | Keine (alles auf einmal) | Gibt alle Modelle zurück (Embedding, Audio, etc.) |

### Filter-Regeln

- **Anthropic**: Alle Modelle verwenden (alle sind Chat-Modelle)
- **OpenAI**: Nur Modelle mit ID-Prefix `gpt-`, `o1-`, `o3-`, `o4-`, `chatgpt-`

### Komponenten

```
src/Llm/
├── LlmModelProviderInterface.php   # Interface: listModels(apiKey): list<LlmModel>
├── ClaudeModelProvider.php          # Anthropic /v1/models API
├── OpenAiModelProvider.php          # OpenAI /v1/models API (gefiltert)
└── LlmModelProviderFactory.php      # Factory: Provider → ModelProvider

src/Dto/
└── LlmModel.php                     # nullable Preise (?float)

src/Service/
└── LlmConfigurationService.php      # PRICING_MAP, getAvailableModels() mit Cache + Fallback
```

### Datenfluss

```
LlmConfigurationService::getAvailableModels(provider, apiKey)
    │
    ├─ Cache vorhanden (< 1h)? → Return cached models
    │
    ├─ LlmModelProviderFactory::create(provider)
    │   └─ LlmModelProviderInterface::listModels(apiKey)
    │       ├─ API-Call → Filter → Sortieren → PRICING_MAP anwenden
    │       └─ Exception → Fallback auf PRICING_MAP-Keys
    │
    └─ Cache speichern → Return models
```

---

## Task 1: LlmModel nullable Preise

**Files:**
- Modify: `src/Dto/LlmModel.php`
- Modify: `tests/Unit/Dto/LlmModelTest.php`

**Step 1: LlmModel-Properties auf `?float` ändern**

```php
// src/Dto/LlmModel.php
final readonly class LlmModel
{
    /**
     * @param ?float $inputCostPerMillion  Cost per million input tokens in USD, null if unknown
     * @param ?float $outputCostPerMillion Cost per million output tokens in USD, null if unknown
     */
    public function __construct(
        public LlmProvider $provider,
        public string $modelId,
        public string $label,
        public ?float $inputCostPerMillion,
        public ?float $outputCostPerMillion,
    ) {
    }

    /**
     * Returns the estimated cost for the given token counts, or null if pricing is unknown.
     */
    public function estimateCost(int $inputTokens, int $outputTokens): ?float
    {
        if ($this->inputCostPerMillion === null || $this->outputCostPerMillion === null) {
            return null;
        }

        return ($inputTokens / 1_000_000) * $this->inputCostPerMillion
            + ($outputTokens / 1_000_000) * $this->outputCostPerMillion;
    }
}
```

**Step 2: Tests anpassen**

Bestehende Tests müssen weiter grün sein. Neuen Test für `null`-Preise hinzufügen:

```php
#[Test]
public function estimateCostReturnsNullWhenPricingUnknown(): void
{
    $model = new LlmModel(LlmProvider::Claude, 'claude-unknown', 'Unknown', null, null);

    self::assertNull($model->estimateCost(1_000_000, 500_000));
}
```

**Step 3: Alle Aufrufer von `estimateCost()` prüfen**

`LlmStatusCommand.php:89` — aktuell:
```php
$estimatedCost = $model->estimateCost($tokens['input'], $tokens['output']);
$io->note(sprintf('Estimated cost so far: $%s', number_format($estimatedCost, 2)));
```

Ändern zu:
```php
$estimatedCost = $model?->estimateCost($tokens['input'], $tokens['output']);

if ($estimatedCost !== null) {
    $io->note(sprintf('Estimated cost so far: $%s', number_format($estimatedCost, 2)));
} else {
    $io->note('Cost estimation not available for this model.');
}
```

**Step 4: Template config.html.twig anpassen**

Zeile 69 — aktuell:
```twig
{{ model.label }} (${{ model.inputCostPerMillion }}/${{ model.outputCostPerMillion }} per 1M tokens)
```

Ändern zu:
```twig
{{ model.label }}{% if model.inputCostPerMillion is not null %} (${{ model.inputCostPerMillion }}/${{ model.outputCostPerMillion }} per 1M tokens){% endif %}
```

Data-Attribute (Zeile 66-67) — Fallback auf leeren String:
```twig
data-input-cost="{{ model.inputCostPerMillion ?? '' }}"
data-output-cost="{{ model.outputCostPerMillion ?? '' }}"
```

**Step 5: `composer ci:cgl && composer ci:rector && composer ci:test`**

**Step 6: Commit**

```
Support nullable pricing in LlmModel for dynamically discovered models
```

---

## Task 2: LlmModelProviderInterface + ClaudeModelProvider

**Files:**
- Create: `src/Llm/LlmModelProviderInterface.php`
- Create: `src/Llm/ClaudeModelProvider.php`
- Create: `tests/Unit/Llm/ClaudeModelProviderTest.php`

**Step 1: Interface definieren**

```php
// src/Llm/LlmModelProviderInterface.php
interface LlmModelProviderInterface
{
    /**
     * Fetch available models from the provider API.
     *
     * @return list<LlmModel>
     */
    public function listModels(string $apiKey): array;
}
```

**Step 2: ClaudeModelProvider implementieren**

```php
// src/Llm/ClaudeModelProvider.php
final readonly class ClaudeModelProvider implements LlmModelProviderInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/models';
    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Fetch available Claude models from the Anthropic API.
     *
     * @return list<LlmModel>
     */
    public function listModels(string $apiKey): array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
            ],
            'query' => [
                'limit' => 1000,
            ],
        ]);

        /** @var array{data: list<array{id: string, display_name: string}>} $data */
        $data = $response->toArray();

        $models = [];

        foreach ($data['data'] as $model) {
            $models[] = new LlmModel(
                provider: LlmProvider::Claude,
                modelId: $model['id'],
                label: $model['display_name'],
                inputCostPerMillion: null,
                outputCostPerMillion: null,
            );
        }

        usort($models, static fn (LlmModel $a, LlmModel $b): int => $a->label <=> $b->label);

        return $models;
    }
}
```

**Step 3: Test mit MockHttpClient**

```php
// tests/Unit/Llm/ClaudeModelProviderTest.php
#[CoversClass(ClaudeModelProvider::class)]
final class ClaudeModelProviderTest extends TestCase
{
    #[Test]
    public function listModelsReturnsModelsFromApi(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
                ['id' => 'claude-haiku-4-5-20251001', 'display_name' => 'Claude Haiku 4.5', 'type' => 'model', 'created_at' => '2025-10-01T00:00:00Z'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new ClaudeModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertCount(2, $models);
        self::assertSame('claude-haiku-4-5-20251001', $models[0]->modelId);
        self::assertSame('Claude Haiku 4.5', $models[0]->label);
        self::assertSame(LlmProvider::Claude, $models[0]->provider);
        self::assertNull($models[0]->inputCostPerMillion);
    }
}
```

**Step 4: `composer ci:cgl && composer ci:rector && composer ci:test`**

**Step 5: Commit**

```
Add ClaudeModelProvider to fetch models from Anthropic API
```

---

## Task 3: OpenAiModelProvider

**Files:**
- Create: `src/Llm/OpenAiModelProvider.php`
- Create: `tests/Unit/Llm/OpenAiModelProviderTest.php`

**Step 1: OpenAiModelProvider implementieren**

```php
// src/Llm/OpenAiModelProvider.php
final readonly class OpenAiModelProvider implements LlmModelProviderInterface
{
    private const string API_URL = 'https://api.openai.com/v1/models';
    private const int TIMEOUT_SECONDS = 10;

    /**
     * Model ID prefixes that indicate chat-capable models.
     */
    private const array CHAT_MODEL_PREFIXES = [
        'gpt-',
        'o1-',
        'o3-',
        'o4-',
        'chatgpt-',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Fetch available chat models from the OpenAI API.
     *
     * Filters out embedding, audio, and other non-chat models.
     *
     * @return list<LlmModel>
     */
    public function listModels(string $apiKey): array
    {
        $response = $this->httpClient->request('GET', self::API_URL, [
            'timeout' => self::TIMEOUT_SECONDS,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
            ],
        ]);

        /** @var array{data: list<array{id: string, owned_by: string}>} $data */
        $data = $response->toArray();

        $models = [];

        foreach ($data['data'] as $model) {
            if (!$this->isChatModel($model['id'])) {
                continue;
            }

            $models[] = new LlmModel(
                provider: LlmProvider::OpenAi,
                modelId: $model['id'],
                label: $model['id'],
                inputCostPerMillion: null,
                outputCostPerMillion: null,
            );
        }

        usort($models, static fn (LlmModel $a, LlmModel $b): int => $a->modelId <=> $b->modelId);

        return $models;
    }

    /**
     * Check whether a model ID belongs to a chat-capable model.
     */
    private function isChatModel(string $modelId): bool
    {
        return array_any(
            self::CHAT_MODEL_PREFIXES,
            static fn (string $prefix): bool => str_starts_with($modelId, $prefix),
        );
    }
}
```

**Step 2: Test mit MockHttpClient**

```php
// tests/Unit/Llm/OpenAiModelProviderTest.php
#[CoversClass(OpenAiModelProvider::class)]
final class OpenAiModelProviderTest extends TestCase
{
    #[Test]
    public function listModelsFiltersNonChatModels(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'gpt-4o-mini', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'text-embedding-3-large', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'whisper-1', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'dall-e-3', 'object' => 'model', 'owned_by' => 'openai'],
                ['id' => 'o1-preview', 'object' => 'model', 'owned_by' => 'openai'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new OpenAiModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertCount(3, $models);

        $ids = array_map(static fn (LlmModel $m): string => $m->modelId, $models);
        self::assertContains('gpt-4o', $ids);
        self::assertContains('gpt-4o-mini', $ids);
        self::assertContains('o1-preview', $ids);
        self::assertNotContains('text-embedding-3-large', $ids);
        self::assertNotContains('whisper-1', $ids);
    }

    #[Test]
    public function listModelsUsesModelIdAsLabel(): void
    {
        $mockResponse = new MockResponse(json_encode([
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'owned_by' => 'openai'],
            ],
        ], JSON_THROW_ON_ERROR));

        $provider = new OpenAiModelProvider(new MockHttpClient($mockResponse));
        $models   = $provider->listModels('test-key');

        self::assertSame('gpt-4o', $models[0]->label);
        self::assertSame(LlmProvider::OpenAi, $models[0]->provider);
        self::assertNull($models[0]->inputCostPerMillion);
    }
}
```

**Step 3: `composer ci:cgl && composer ci:rector && composer ci:test`**

**Step 4: Commit**

```
Add OpenAiModelProvider with chat model filtering
```

---

## Task 4: LlmModelProviderFactory

**Files:**
- Create: `src/Llm/LlmModelProviderFactory.php`
- Create: `tests/Unit/Llm/LlmModelProviderFactoryTest.php`

**Step 1: Factory implementieren**

```php
// src/Llm/LlmModelProviderFactory.php
final readonly class LlmModelProviderFactory
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Create a model provider for the given LLM provider.
     */
    public function create(LlmProvider $provider): LlmModelProviderInterface
    {
        return match ($provider) {
            LlmProvider::Claude => new ClaudeModelProvider($this->httpClient),
            LlmProvider::OpenAi => new OpenAiModelProvider($this->httpClient),
        };
    }
}
```

**Step 2: Tests**

```php
#[Test]
public function createReturnsClaudeModelProvider(): void
{
    $factory  = new LlmModelProviderFactory(new MockHttpClient());
    $provider = $factory->create(LlmProvider::Claude);

    self::assertInstanceOf(ClaudeModelProvider::class, $provider);
}

#[Test]
public function createReturnsOpenAiModelProvider(): void
{
    $factory  = new LlmModelProviderFactory(new MockHttpClient());
    $provider = $factory->create(LlmProvider::OpenAi);

    self::assertInstanceOf(OpenAiModelProvider::class, $provider);
}
```

**Step 3: `composer ci:cgl && composer ci:rector && composer ci:test`**

**Step 4: Commit**

```
Add LlmModelProviderFactory for provider-specific model listing
```

---

## Task 5: LlmConfigurationService — dynamische Modell-Liste mit Cache + Fallback

**Files:**
- Modify: `src/Service/LlmConfigurationService.php`
- Modify: `tests/Unit/Service/LlmConfigurationServiceTest.php`

**Step 1: PRICING_MAP und Cache-Integration**

`LlmConfigurationService` erhält `LlmModelProviderFactory` und `CacheInterface` als neue Abhängigkeiten.

```php
// src/Service/LlmConfigurationService.php
use App\Llm\LlmModelProviderFactory;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class LlmConfigurationService
{
    private const string CONFIG_FILENAME = 'llm_config.yaml';
    private const int MODEL_CACHE_TTL = 3600; // 1 hour

    /**
     * Known model pricing: model ID => [input cost per million, output cost per million].
     *
     * @var array<string, array{float, float}>
     */
    private const array PRICING_MAP = [
        // Claude
        'claude-haiku-4-5-20251001' => [0.80, 4.00],
        'claude-sonnet-4-6'         => [3.00, 15.00],
        'claude-opus-4-6'           => [15.00, 75.00],
        // OpenAI
        'gpt-4o-mini'               => [0.15, 0.60],
        'gpt-4o'                    => [2.50, 10.00],
        'o1-preview'                => [15.00, 60.00],
        'o1-mini'                   => [3.00, 12.00],
    ];

    public function __construct(
        private string $dataDir,
        private LlmModelProviderFactory $modelProviderFactory,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }
```

**Step 2: `getAvailableModels()` umschreiben**

```php
    /**
     * Returns available models for the given provider.
     *
     * Fetches models from the provider API (cached for 1 hour),
     * enriches them with known pricing, and falls back to a static
     * list from the pricing map if the API is unreachable.
     *
     * @return list<LlmModel>
     */
    public function getAvailableModels(?LlmProvider $provider = null, ?string $apiKey = null): array
    {
        $provider ??= $this->load()->provider;
        $apiKey   ??= $this->load()->apiKey;

        if ($apiKey === '') {
            return $this->getStaticModels($provider);
        }

        $cacheKey = 'llm_models_' . $provider->value;

        try {
            /** @var list<LlmModel> */
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($provider, $apiKey): array {
                $item->expiresAfter(self::MODEL_CACHE_TTL);

                $modelProvider = $this->modelProviderFactory->create($provider);
                $models        = $modelProvider->listModels($apiKey);

                return $this->enrichWithPricing($models);
            });
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch models from API, using static fallback.', [
                'provider' => $provider->value,
                'error'    => $e->getMessage(),
            ]);

            return $this->getStaticModels($provider);
        }
    }

    /**
     * Enrich models with known pricing from the local pricing map.
     *
     * @param list<LlmModel> $models
     *
     * @return list<LlmModel>
     */
    private function enrichWithPricing(array $models): array
    {
        $enriched = [];

        foreach ($models as $model) {
            $pricing = self::PRICING_MAP[$model->modelId] ?? null;

            $enriched[] = new LlmModel(
                provider: $model->provider,
                modelId: $model->modelId,
                label: $model->label,
                inputCostPerMillion: $pricing[0] ?? null,
                outputCostPerMillion: $pricing[1] ?? null,
            );
        }

        return $enriched;
    }

    /**
     * Build a static model list from the pricing map as fallback.
     *
     * @return list<LlmModel>
     */
    private function getStaticModels(LlmProvider $provider): array
    {
        $models = [];

        foreach (self::PRICING_MAP as $modelId => $pricing) {
            $modelProvider = $this->guessProvider($modelId);

            if ($modelProvider !== $provider) {
                continue;
            }

            $models[] = new LlmModel(
                provider: $modelProvider,
                modelId: $modelId,
                label: $modelId,
                inputCostPerMillion: $pricing[0],
                outputCostPerMillion: $pricing[1],
            );
        }

        return $models;
    }

    /**
     * Guess the provider from a model ID based on naming conventions.
     */
    private function guessProvider(string $modelId): LlmProvider
    {
        if (str_starts_with($modelId, 'claude')) {
            return LlmProvider::Claude;
        }

        return LlmProvider::OpenAi;
    }
```

**Step 3: Tests anpassen**

Die bestehenden Tests nutzen `LlmConfigurationService` direkt mit `$dataDir`. Da jetzt `LlmModelProviderFactory`, `CacheInterface` und `LoggerInterface` Pflicht sind, müssen die Tests angepasst werden. Verwende `ArrayAdapter` als In-Memory Cache und `NullLogger`.

```php
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Psr\Log\NullLogger;

private function createService(?string $dataDir = null): LlmConfigurationService
{
    return new LlmConfigurationService(
        $dataDir ?? $this->tempDir,
        new LlmModelProviderFactory(new MockHttpClient()),
        new ArrayAdapter(),
        new NullLogger(),
    );
}
```

Neuen Test für dynamische Modelle:

```php
#[Test]
public function getAvailableModelsReturnsDynamicModelsFromApi(): void
{
    $mockResponse = new MockResponse(json_encode([
        'data' => [
            ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => 'claude-unknown-model', 'display_name' => 'Claude Unknown', 'type' => 'model', 'created_at' => '2026-01-01T00:00:00Z'],
        ],
    ], JSON_THROW_ON_ERROR));

    $service = new LlmConfigurationService(
        $this->tempDir,
        new LlmModelProviderFactory(new MockHttpClient($mockResponse)),
        new ArrayAdapter(),
        new NullLogger(),
    );

    $models = $service->getAvailableModels(LlmProvider::Claude, 'test-key');

    self::assertCount(2, $models);

    // Known model has pricing
    $sonnet = array_find($models, static fn (LlmModel $m): bool => $m->modelId === 'claude-sonnet-4-6');
    self::assertNotNull($sonnet);
    self::assertSame(3.00, $sonnet->inputCostPerMillion);
    self::assertSame(15.00, $sonnet->outputCostPerMillion);

    // Unknown model has null pricing
    $unknown = array_find($models, static fn (LlmModel $m): bool => $m->modelId === 'claude-unknown-model');
    self::assertNotNull($unknown);
    self::assertNull($unknown->inputCostPerMillion);
}

#[Test]
public function getAvailableModelsFallsBackToStaticListOnApiError(): void
{
    $mockResponse = new MockResponse('', ['http_code' => 500]);

    $service = new LlmConfigurationService(
        $this->tempDir,
        new LlmModelProviderFactory(new MockHttpClient($mockResponse)),
        new ArrayAdapter(),
        new NullLogger(),
    );

    $models = $service->getAvailableModels(LlmProvider::Claude, 'test-key');

    self::assertNotEmpty($models);

    // Static fallback models all have pricing
    foreach ($models as $model) {
        self::assertNotNull($model->inputCostPerMillion);
    }
}
```

**Step 4: Controller anpassen**

In `LlmController::config()` die Modell-Liste mit Provider und API-Key laden:

```php
// Zeile 73 ändern von:
'models' => $configService->getAvailableModels(),
// zu:
'models' => $configService->getAvailableModels($config->provider, $config->apiKey),
```

**Step 5: LlmStatusCommand anpassen**

`getAvailableModels()` wird jetzt mit Parametern aufgerufen:

```php
// Zeile 59-62 ändern von:
$model = array_find(
    $this->configService->getAvailableModels(),
    static fn ($m): bool => $m->modelId === $config->modelId,
);
// zu:
$model = array_find(
    $this->configService->getAvailableModels($config->provider, $config->apiKey),
    static fn (LlmModel $m): bool => $m->modelId === $config->modelId,
);
```

**Step 6: `composer ci:cgl && composer ci:rector && composer ci:test`**

**Step 7: Commit**

```
Fetch models dynamically from provider APIs with caching and static fallback
```

---

## Task 6: Cache-Invalidierung bei Provider-Wechsel

**Files:**
- Modify: `src/Service/LlmConfigurationService.php`

**Step 1: Cache beim Speichern invalidieren**

Wenn der Provider wechselt, muss der Model-Cache invalidiert werden, damit beim nächsten Laden die richtigen Modelle abgefragt werden.

In `save()` am Ende hinzufügen:

```php
public function save(LlmConfiguration $config): void
{
    // ... existing save logic ...

    // Invalidate model cache for both providers so a provider switch
    // fetches fresh models on the next config page load
    $this->cache->delete('llm_models_claude');
    $this->cache->delete('llm_models_openai');
}
```

**Step 2: `composer ci:cgl && composer ci:rector && composer ci:test`**

**Step 3: Commit**

```
Invalidate model cache when LLM configuration is saved
```

---

## Task 7: Endgültige Tests, Code-Review, Cleanup

**Step 1:** `composer ci:cgl && composer ci:rector`
**Step 2:** `composer ci:test` — alle Tests grün
**Step 3:** Code-Review aller neuen/geänderten Dateien
**Step 4:** CLAUDE.md Roadmap prüfen — ggf. neuen Punkt ergänzen

---

## Zusammenfassung

| Task | Komponente | Dateien |
|------|-----------|--------|
| 1 | Nullable Preise | `LlmModel.php`, `LlmStatusCommand.php`, `config.html.twig` |
| 2 | Claude Model Provider | `ClaudeModelProvider.php`, `LlmModelProviderInterface.php` |
| 3 | OpenAI Model Provider | `OpenAiModelProvider.php` |
| 4 | Model Provider Factory | `LlmModelProviderFactory.php` |
| 5 | Dynamische Modell-Liste | `LlmConfigurationService.php`, `LlmController.php`, `LlmStatusCommand.php` |
| 6 | Cache-Invalidierung | `LlmConfigurationService.php` |
| 7 | Tests + Review | Alle Dateien |
