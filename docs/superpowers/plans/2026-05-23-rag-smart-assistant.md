# RAG Smart Assistant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить RAG-слой к существующему AI Assistant, чтобы ответы ассистента опирались на релевантные проектные факты, права пользователя и проверяемые источники.

**Architecture:** Laravel остается trusted executor: он сам собирает, индексирует, фильтрует и передает модели только разрешенный контекст. RAG добавляется как отдельный bounded layer внутри `AIAssistant`, не заменяя текущие tools, reports и agent state. Первый релиз индексирует доменные факты проекта, а не произвольные файлы: проекты, график, договоры, закупки, склад, заявки, финансы и Project Pulse.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL + pgvector, Laravel queues/jobs, existing `openai-php/client`, existing `LLMProviderInterface`, `AdminResponse`/`MobileResponse`, React/Vite/TypeScript admin UI, PHPUnit/Pest, Larastan/PHPStan, Vitest.

---

## Source Notes

- Context7 pgvector docs: use `vector(1536)` for 1536-dimensional embeddings, cosine search with `<=>`, and HNSW index with `vector_cosine_ops`; bulk load first, then create the vector index for large backfills.
- Context7 Laravel 11 docs: indexing must be a queued `ShouldQueue` job with a `handle` method, named queue support via `onQueue`, and tests should assert job dispatch with `Queue::fake()`.
- Context7 openai-php/client docs: embeddings are created through `$client->embeddings()->create(['model' => 'text-embedding-3-small', 'input' => $text])`; result vectors are read from `$response->embeddings[0]->embedding`.

## Scope Decision

### MVP includes

- Project-scoped semantic context for `POST /api/v1/admin/ai-assistant/chat`.
- Organization and permission scoped retrieval.
- Indexed source types:
  - project summary
  - schedule snapshot
  - contract snapshot
  - procurement snapshot
  - warehouse snapshot
  - site request snapshot
  - work completion snapshot
  - Project Pulse report summary
- Evidence returned in message metadata so the UI can show where the answer came from.
- Deterministic fallback when no relevant context is found.

### MVP excludes

- OCR, PDF parsing, DOCX parsing and arbitrary file upload indexing.
- Realtime streaming/SSE.
- Cross-organization search.
- LLM-driven SQL generation.
- Write actions based only on retrieved text.

### Follow-up phase

- Document ingestion via S3 files.
- Hybrid search with full-text ranking plus vector distance.
- Evaluation dashboard for prompt quality and retrieval quality.
- Mobile UI source panel.

## File Structure Map

### Backend: new files

- `app/BusinessModules/Features/AIAssistant/DTOs/Rag/RagChunkData.php`  
  Immutable DTO for a normalized chunk before persistence.

- `app/BusinessModules/Features/AIAssistant/DTOs/Rag/RagSearchResult.php`  
  DTO for a retrieved context item returned to prompt assembly and API metadata.

- `app/BusinessModules/Features/AIAssistant/Models/RagSource.php`  
  Eloquent model for indexed source records.

- `app/BusinessModules/Features/AIAssistant/Models/RagChunk.php`  
  Eloquent model for text chunks and vector metadata.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/RagEmbeddingProviderInterface.php`  
  Contract for embedding generation.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/OpenAIRagEmbeddingProvider.php`  
  OpenAI embedding provider using the installed `openai-php/client`.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/RagSourceRegistry.php`  
  Registry for domain source collectors.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/RagIndexer.php`  
  Creates or updates `rag_sources` and `rag_chunks`.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/RagRetriever.php`  
  Performs permission-scoped semantic search.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/RagPromptContextBuilder.php`  
  Converts search results into compact prompt context and metadata evidence.

- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ProjectRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ScheduleRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ContractRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ProcurementRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/WarehouseRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/SiteRequestRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/WorkCompletionRagSource.php`
- `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ProjectPulseRagSource.php`  
  Domain collectors. Each collector returns normalized `RagChunkData` and owns its source-specific query.

- `app/BusinessModules/Features/AIAssistant/Jobs/IndexRagSourceJob.php`  
  Background job for one source/entity.

- `app/BusinessModules/Features/AIAssistant/Console/Commands/BackfillRagIndexCommand.php`  
  Safe backfill command. Do not run in production without explicit user approval.

- `app/BusinessModules/Features/AIAssistant/migrations/2026_05_23_000001_create_ai_rag_tables.php`

- `tests/Unit/AIAssistant/Rag/*Test.php`
- `tests/Feature/Api/V1/Admin/AIAssistantRagContextTest.php`

### Backend: existing files to modify

- `app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php`  
  Register RAG services and collectors.

- `app/BusinessModules/Features/AIAssistant/Services/AIAssistantService.php`  
  Build RAG context before LLM request and expose evidence in message metadata.

- `app/BusinessModules/Features/AIAssistant/Services/Agent/AssistantResponseVerifier.php`  
  Add source-grounding guard for answers that cite RAG evidence.

- `app/BusinessModules/Features/AIAssistant/Http/Resources/MessageResource.php`  
  Keep message metadata backward compatible and expose `rag_context`.

- `app/BusinessModules/Features/AIAssistant/config/ai-assistant.php`  
  Add RAG config: enabled, provider, model, dimensions, limits, thresholds, queue.

- `lang/ru/ai_assistant.php`  
  Add user-facing messages for no context, context unavailable, and indexing unavailable.

- `tests/Unit/AIAssistant/AIAssistantSourceEncodingTest.php`  
  Expand mojibake coverage to all RAG files and existing AI assistant strings touched by the implementation.

### Admin: existing files to modify

- `../prohelper_admin/src/types/aiAssistant.ts`  
  Add typed `rag_context`, `sources`, `score`, `entity_ref`.

- `../prohelper_admin/src/services/aiAssistantService.ts`  
  Normalize new metadata without breaking existing artifact/report fields.

- `../prohelper_admin/src/pages/AIAssistant/AIAssistantChatPage.tsx`  
  Render a compact source list for grounded answers.

- `../prohelper_admin/src/services/aiAssistantService.test.ts`  
  Add normalization tests for RAG metadata.

## Data Contract

### `message.metadata.rag_context`

```json
{
  "enabled": true,
  "used": true,
  "query": "что горит по графику на объекте Литер А",
  "sources": [
    {
      "source_type": "schedule",
      "entity_type": "project",
      "entity_id": 56,
      "title": "График работ: Литер А",
      "excerpt": "Монтаж перекрытий отстает на 4 дня, потому что закрытие предыдущего этапа перенесено на 27 мая.",
      "score": 0.84,
      "updated_at": "2026-05-23T10:00:00+03:00"
    }
  ],
  "limits": {
    "requested": 8,
    "returned": 3
  }
}
```

### Retrieval rules

- Never retrieve chunks outside `organization_id`.
- Apply project/entity access before vector search result is allowed into the prompt.
- Return at most 8 chunks to the prompt.
- Drop chunks below similarity threshold from config.
- If no context passes filters, answer with regular assistant flow and metadata `rag_context.used = false`.
- Do not use retrieved text as authorization for write actions.

## Task 0: Baseline And Encoding Safety

**Files:**
- Modify: `tests/Unit/AIAssistant/AIAssistantSourceEncodingTest.php`
- Modify: existing touched AI assistant files that contain mojibake strings

- [x] **Step 1: Expand failing encoding test**

Add these paths to `criticalSourceProvider()`:

```php
'assistant config' => ['app/BusinessModules/Features/AIAssistant/config/ai-assistant.php'],
'agent executor' => ['app/BusinessModules/Features/AIAssistant/Services/Agent/AssistantAgentExecutor.php'],
'response verifier' => ['app/BusinessModules/Features/AIAssistant/Services/Agent/AssistantResponseVerifier.php'],
'admin assistant service' => ['../prohelper_admin/src/services/aiAssistantService.ts'],
'admin assistant types' => ['../prohelper_admin/src/types/aiAssistant.ts'],
```

- [x] **Step 2: Run the focused test**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php
```

Expected: FAIL if touched files contain mojibake markers.

- [x] **Step 3: Replace user-facing fallback strings with translations**

Use `trans_message('ai_assistant.*')` in backend files that return user-facing strings. Keep technical log event names in English.

Required keys in `lang/ru/ai_assistant.php`:

```php
'rag_no_relevant_context' => 'Не нашел достаточно надежного контекста по этому вопросу.',
'rag_context_unavailable' => 'Контекст проекта временно недоступен. Попробуйте еще раз позже.',
'rag_indexing_unavailable' => 'Индекс проекта пока обновляется. Часть данных может не учитываться.',
'rag_sources_title' => 'Источники ответа',
```

- [x] **Step 4: Re-run encoding test**

Run:

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php
```

Expected: PASS.

- [x] **Step 5: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant lang/ru tests/Unit/AIAssistant/AIAssistantSourceEncodingTest.php
git commit -m "test[backend]: зафиксирована кодировка строк AI-ассистента"
```

## Task 1: Add RAG Schema And Models

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/migrations/2026_05_23_000001_create_ai_rag_tables.php`
- Create: `app/BusinessModules/Features/AIAssistant/Models/RagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Models/RagChunk.php`
- Test: `tests/Unit/AIAssistant/Rag/RagModelTest.php`

- [x] **Step 1: Write model tests**

Create `tests/Unit/AIAssistant/Rag/RagModelTest.php` with assertions for table names, casts and relations:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use PHPUnit\Framework\TestCase;

class RagModelTest extends TestCase
{
    public function test_source_model_contract(): void
    {
        $source = new RagSource();

        $this->assertSame('ai_rag_sources', $source->getTable());
        $this->assertContains('metadata', array_keys($source->getCasts()));
        $this->assertContains('indexed_at', array_keys($source->getCasts()));
    }

    public function test_chunk_model_contract(): void
    {
        $chunk = new RagChunk();

        $this->assertSame('ai_rag_chunks', $chunk->getTable());
        $this->assertContains('metadata', array_keys($chunk->getCasts()));
        $this->assertContains('embedding_created_at', array_keys($chunk->getCasts()));
    }
}
```

- [x] **Step 2: Run tests to verify failure**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\RagModelTest.php
```

Expected: FAIL because models do not exist.

- [x] **Step 3: Create migration**

Migration structure:

```php
Schema::create('ai_rag_sources', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
    $table->string('source_type', 80);
    $table->string('entity_type', 80);
    $table->string('entity_id', 80);
    $table->string('title');
    $table->string('checksum', 64);
    $table->jsonb('metadata')->nullable();
    $table->timestampTz('indexed_at')->nullable();
    $table->timestampsTz();

    $table->unique(['organization_id', 'source_type', 'entity_type', 'entity_id'], 'ai_rag_sources_unique_entity');
    $table->index(['organization_id', 'project_id', 'source_type'], 'ai_rag_sources_scope_idx');
});

Schema::create('ai_rag_chunks', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('source_id')->constrained('ai_rag_sources')->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
    $table->unsignedInteger('chunk_index');
    $table->text('content');
    $table->string('content_hash', 64);
    $table->jsonb('metadata')->nullable();
    $table->string('embedding_provider', 40)->nullable();
    $table->string('embedding_model', 120)->nullable();
    $table->timestampTz('embedding_created_at')->nullable();
    $table->timestampsTz();

    $table->unique(['source_id', 'chunk_index'], 'ai_rag_chunks_source_index_unique');
    $table->index(['organization_id', 'project_id'], 'ai_rag_chunks_scope_idx');
});

DB::statement('ALTER TABLE ai_rag_chunks ADD COLUMN embedding vector(1536)');
DB::statement('CREATE INDEX ai_rag_chunks_embedding_hnsw_idx ON ai_rag_chunks USING hnsw (embedding vector_cosine_ops)');
```

Do not run `artisan migrate`.

- [x] **Step 4: Create models**

`RagSource` fillable: `organization_id`, `project_id`, `source_type`, `entity_type`, `entity_id`, `title`, `checksum`, `metadata`, `indexed_at`.

`RagChunk` fillable: `source_id`, `organization_id`, `project_id`, `chunk_index`, `content`, `content_hash`, `metadata`, `embedding_provider`, `embedding_model`, `embedding_created_at`.

- [x] **Step 5: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\RagModelTest.php
php -l app\BusinessModules\Features\AIAssistant\Models\RagSource.php
php -l app\BusinessModules\Features\AIAssistant\Models\RagChunk.php
php -l app\BusinessModules\Features\AIAssistant\migrations\2026_05_23_000001_create_ai_rag_tables.php
```

Expected: PASS.

- [x] **Step 6: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant/migrations app/BusinessModules/Features/AIAssistant/Models tests/Unit/AIAssistant/Rag/RagModelTest.php
git commit -m "feat[backend]: добавлены таблицы RAG для AI-ассистента"
```

## Task 2: Add Embedding Provider

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/RagEmbeddingProviderInterface.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/OpenAIRagEmbeddingProvider.php`
- Modify: `app/BusinessModules/Features/AIAssistant/config/ai-assistant.php`
- Test: `tests/Unit/AIAssistant/Rag/OpenAIRagEmbeddingProviderTest.php`

- [x] **Step 1: Write provider contract test**

Test expectations:

```php
$provider = new FakeRagEmbeddingProvider([0.1, 0.2, 0.3]);

$this->assertSame([0.1, 0.2, 0.3], $provider->embed('тестовый фрагмент'));
$this->assertSame('fake', $provider->provider());
$this->assertSame('fake-model', $provider->model());
$this->assertSame(3, $provider->dimensions());
```

- [x] **Step 2: Define interface**

```php
interface RagEmbeddingProviderInterface
{
    /**
     * @return array<int, float>
     */
    public function embed(string $text): array;

    public function provider(): string;

    public function model(): string;

    public function dimensions(): int;
}
```

- [x] **Step 3: Implement OpenAI provider**

Behavior:

- Read API key from `config('ai-assistant.llm.openai.api_key')`.
- Read model from `config('ai-assistant.rag.embedding_model', 'text-embedding-3-small')`.
- Call `$client->embeddings()->create(['model' => $model, 'input' => $text])`.
- Return the first embedding as `array<int, float>`.
- Throw `RuntimeException` with translated message when provider is unavailable.

- [x] **Step 4: Add config**

```php
'rag' => [
    'enabled' => $configEnv('AI_RAG_ENABLED', false),
    'embedding_provider' => $configEnv('AI_RAG_EMBEDDING_PROVIDER', 'openai'),
    'embedding_model' => $configEnv('AI_RAG_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'embedding_dimensions' => $configEnv('AI_RAG_EMBEDDING_DIMENSIONS', 1536),
    'queue' => $configEnv('AI_RAG_QUEUE', 'ai-rag'),
    'max_chunks' => $configEnv('AI_RAG_MAX_CHUNKS', 8),
    'min_similarity' => $configEnv('AI_RAG_MIN_SIMILARITY', 0.72),
    'chunk_chars' => $configEnv('AI_RAG_CHUNK_CHARS', 1200),
],
```

- [x] **Step 5: Register provider**

In `AIAssistantServiceProvider`, bind `RagEmbeddingProviderInterface` to `OpenAIRagEmbeddingProvider` when provider is `openai`.

- [x] **Step 6: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\OpenAIRagEmbeddingProviderTest.php
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface.php
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider.php
```

- [x] **Step 7: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant/config app/BusinessModules/Features/AIAssistant/Services/Rag tests/Unit/AIAssistant/Rag
git commit -m "feat[backend]: добавлен провайдер embeddings для RAG"
```

## Task 3: Add Source Registry And First Collectors

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/DTOs/Rag/RagChunkData.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/RagSourceCollectorInterface.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/RagSourceRegistry.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ProjectRagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ScheduleRagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ContractRagSource.php`
- Test: `tests/Unit/AIAssistant/Rag/RagSourceRegistryTest.php`

- [x] **Step 1: Write registry test**

Assert that registry returns collectors by source type and ignores disabled sources:

```php
$registry = new RagSourceRegistry([
    new FakeRagSourceCollector('project', true),
    new FakeRagSourceCollector('schedule', false),
]);

$this->assertSame(['project'], array_keys($registry->enabledCollectors()));
$this->assertSame('project', $registry->collector('project')->sourceType());
$this->assertNull($registry->collector('missing'));
```

- [x] **Step 2: Define chunk DTO**

Fields:

- `organizationId`
- `projectId`
- `sourceType`
- `entityType`
- `entityId`
- `title`
- `content`
- `metadata`
- `updatedAt`

- [x] **Step 3: Define collector interface**

```php
interface RagSourceCollectorInterface
{
    public function sourceType(): string;

    public function enabled(): bool;

    /**
     * @return iterable<RagChunkData>
     */
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable;

    /**
     * @return iterable<RagChunkData>
     */
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable;
}
```

- [x] **Step 4: Implement first collectors**

Project collector content must include project name, status, dates, address, budget and customer-facing summary if present.

Schedule collector content must include schedule title, active tasks, overdue tasks, nearest dates and percent progress.

Contract collector content must include contract number, contractor, status, amount, dates and linked project.

Every collector must scope by `organization_id` and optional `project_id`.

- [x] **Step 5: Register collectors**

Register collectors in `AIAssistantServiceProvider` through `RagSourceRegistry`.

- [x] **Step 6: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\RagSourceRegistryTest.php
vendor\bin\phpunit tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php
```

- [x] **Step 7: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant/DTOs/Rag app/BusinessModules/Features/AIAssistant/Services/Rag tests/Unit/AIAssistant/Rag
git commit -m "feat[backend]: добавлены источники RAG для проектного контекста"
```

## Task 4: Add Indexer And Queue Job

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/RagIndexer.php`
- Create: `app/BusinessModules/Features/AIAssistant/Jobs/IndexRagSourceJob.php`
- Create: `app/BusinessModules/Features/AIAssistant/Console/Commands/BackfillRagIndexCommand.php`
- Modify: `app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php`
- Test: `tests/Unit/AIAssistant/Rag/RagIndexerTest.php`
- Test: `tests/Unit/AIAssistant/Rag/IndexRagSourceJobTest.php`
- Test: `tests/Feature/Console/AIAssistantRagBackfillCommandTest.php`

- [x] **Step 1: Write indexer tests**

Cover:

- same source/entity updates existing `RagSource`
- unchanged checksum does not recreate chunks
- changed content replaces chunks
- embedding provider is called once per chunk
- vector is stored via parameterized DB update, not string concatenation

- [x] **Step 2: Implement `RagIndexer`**

Public methods:

```php
public function indexChunk(RagChunkData $chunk): void;

public function indexOrganization(int $organizationId, ?int $projectId = null, ?string $sourceType = null): int;
```

Rules:

- Compute checksum from normalized title + content + metadata.
- Use `DB::transaction(static function () use ($chunk, $embedding): void { $source->save(); $chunkModel->save(); DB::update('UPDATE ai_rag_chunks SET embedding = ?::vector WHERE id = ?', [$vector, $chunkModel->id]); });` with the real `$source`, `$chunkModel` and `$vector` variables from the indexer implementation.
- Store embedding only after chunk row exists.
- Store vector through bound parameter compatible with pgvector string format.
- Log provider failures without exposing API keys.

- [x] **Step 3: Implement queue job**

`IndexRagSourceJob`:

- implements `ShouldQueue`
- uses `Queueable`
- has constructor `public function __construct(public int $organizationId, public ?int $projectId = null, public ?string $sourceType = null)`
- calls `$indexer->indexOrganization($this->organizationId, $this->projectId, $this->sourceType)`
- sets queue from `config('ai-assistant.rag.queue', 'ai-rag')`
- has `failed(?Throwable $exception): void` with `Log::warning('ai.rag.index.failed', ['organization_id' => $this->organizationId, 'project_id' => $this->projectId, 'source_type' => $this->sourceType, 'exception_class' => $exception?::class])`

- [x] **Step 4: Implement backfill command**

Command signature:

```php
protected $signature = 'ai-assistant:rag-backfill {organization_id} {--project_id=} {--source_type=} {--sync}';
```

Behavior:

- With `--sync`, call indexer directly.
- Without `--sync`, dispatch `IndexRagSourceJob`.
- Never runs migrations.
- Output count of dispatched or indexed sources.

- [x] **Step 5: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\RagIndexerTest.php tests\Unit\AIAssistant\Rag\IndexRagSourceJobTest.php
vendor\bin\phpunit tests\Feature\Console\AIAssistantRagBackfillCommandTest.php
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer.php
php -l app\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob.php
php -l app\BusinessModules\Features\AIAssistant\Console\Commands\BackfillRagIndexCommand.php
```

- [x] **Step 6: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant/Services/Rag app/BusinessModules/Features/AIAssistant/Jobs app/BusinessModules/Features/AIAssistant/Console tests/Unit/AIAssistant/Rag
git commit -m "feat[backend]: добавлена индексация RAG-контекста"
```

## Task 5: Add Permission-Scoped Retrieval

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/DTOs/Rag/RagSearchResult.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/RagRetriever.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/RagPromptContextBuilder.php`
- Test: `tests/Unit/AIAssistant/Rag/RagRetrieverTest.php`
- Test: `tests/Unit/AIAssistant/Rag/RagPromptContextBuilderTest.php`

- [x] **Step 1: Write retriever tests**

Cover:

- filters by `organization_id`
- filters by allowed `project_id`
- excludes chunks below configured similarity
- orders by cosine distance
- returns evidence-safe excerpts

- [x] **Step 2: Implement `RagSearchResult`**

Fields:

- `sourceType`
- `entityType`
- `entityId`
- `projectId`
- `title`
- `excerpt`
- `similarity`
- `metadata`
- `updatedAt`

- [x] **Step 3: Implement `RagRetriever`**

Method:

```php
/**
 * @return array<int, RagSearchResult>
 */
public function search(string $query, int $organizationId, User $user, array $requestContext = []): array;
```

SQL pattern:

```sql
SELECT c.*, s.source_type, s.entity_type, s.entity_id, s.title,
       1 - (c.embedding <=> ?::vector) AS similarity
FROM ai_rag_chunks c
JOIN ai_rag_sources s ON s.id = c.source_id
WHERE c.organization_id = ?
  AND c.embedding IS NOT NULL
ORDER BY c.embedding <=> ?::vector
LIMIT ?
```

Then apply permission and project filters before returning results.

- [x] **Step 4: Implement prompt context builder**

Output:

```php
[
    'prompt' => "Контекст из ProHelper:\n[1] График работ: монтаж перекрытий отстает на 4 дня, ответственный участок - монолит.",
    'metadata' => [
        'enabled' => true,
        'used' => true,
        'sources' => [
            [
                'source_type' => 'schedule',
                'entity_type' => 'project',
                'entity_id' => 56,
                'title' => 'График работ: Литер А',
                'excerpt' => 'Монтаж перекрытий отстает на 4 дня.',
                'score' => 0.84,
            ],
        ],
        'limits' => ['requested' => 8, 'returned' => count($results)],
    ],
]
```

- [x] **Step 5: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\RagRetrieverTest.php tests\Unit\AIAssistant\Rag\RagPromptContextBuilderTest.php
```

- [x] **Step 6: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant/DTOs/Rag app/BusinessModules/Features/AIAssistant/Services/Rag tests/Unit/AIAssistant/Rag
git commit -m "feat[backend]: добавлен поиск RAG-контекста с учетом прав"
```

## Task 6: Integrate RAG Into Assistant Flow

**Files:**
- Modify: `app/BusinessModules/Features/AIAssistant/Services/AIAssistantService.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Services/Agent/AssistantResponseVerifier.php`
- Modify: `app/BusinessModules/Features/AIAssistant/Http/Resources/MessageResource.php`
- Test: `tests/Unit/AIAssistant/AIAssistantServiceBudgetTest.php`
- Test: `tests/Feature/Api/V1/Admin/AIAssistantRagContextTest.php`

- [x] **Step 1: Write feature test**

Scenario:

- User asks: `Что сейчас тормозит проект Литер А?`
- Indexed chunk exists for same organization and accessible project.
- Chat response metadata includes `rag_context.used = true`.
- Prompt sent to fake LLM includes source excerpt.
- Response metadata includes source title and entity reference.

- [x] **Step 2: Add RAG dependency to `AIAssistantService`**

Constructor dependency:

```php
private readonly RagPromptContextBuilder $ragPromptContextBuilder
```

Use it before the `buildMessages` call and append compact RAG context to the system or tool-context section, staying inside `MESSAGE_CHAR_BUDGET`.

- [x] **Step 3: Persist RAG metadata**

When storing assistant message, add:

```php
'rag_context' => $ragContext['metadata'],
```

Do not remove existing `structured_payload`, `artifacts`, `agent_state`, or `tool_result`.

- [x] **Step 4: Add verifier guard**

Rules:

- If answer says it used project context but `rag_context.used = false`, rewrite to a safe answer.
- If answer cites source numbers not present in metadata, strip those source claims.
- Report artifacts remain governed by existing artifact verifier.

- [x] **Step 5: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant tests\Feature\Api\V1\Admin\AIAssistantRagContextTest.php
vendor\bin\phpunit tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php
```

- [x] **Step 6: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant tests/Feature/Api/V1/Admin/AIAssistantRagContextTest.php tests/Unit/AIAssistant
git commit -m "feat[backend]: подключен RAG-контекст к AI-ассистенту"
```

## Task 7: Add Admin Metadata Rendering

**Files:**
- Modify: `../prohelper_admin/src/types/aiAssistant.ts`
- Modify: `../prohelper_admin/src/services/aiAssistantService.ts`
- Modify: `../prohelper_admin/src/pages/AIAssistant/AIAssistantChatPage.tsx`
- Create: `../prohelper_admin/src/pages/AIAssistant/ragSources.ts`
- Test: `../prohelper_admin/src/services/aiAssistantService.test.ts`
- Test: `../prohelper_admin/src/pages/AIAssistant/ragSources.test.ts`

- [x] **Step 1: Extend TypeScript types**

Add:

```ts
export interface AssistantRagSource {
  source_type: string;
  entity_type: string;
  entity_id?: string | number | null;
  project_id?: number | null;
  title: string;
  excerpt?: string | null;
  score?: number | null;
  updated_at?: string | null;
}

export interface AssistantRagContext {
  enabled?: boolean;
  used?: boolean;
  query?: string | null;
  sources?: AssistantRagSource[];
  limits?: {
    requested?: number;
    returned?: number;
  };
}
```

Add `rag_context?: AssistantRagContext | null` to `AssistantStructuredPayload`.

- [x] **Step 2: Add service normalization test**

Input metadata with `rag_context.sources` must normalize missing optional fields without throwing.

- [x] **Step 3: Normalize RAG context in service**

In `normalizeMetadata`, map `rag_context.sources` to safe arrays and keep unknown fields.

- [x] **Step 4: Render compact source block**

In chat page, show source list below assistant answer only when `metadata.rag_context.used === true` and sources exist.
The visibility rule is covered by `src/pages/AIAssistant/ragSources.test.ts`.

UI text:

- title: `Источники ответа`
- empty hidden state: do not show a block
- source row: title, source type label, optional updated date

- [x] **Step 5: Run frontend checks**

Do not run `npm run build`.

```powershell
npx tsc --noEmit
npx vitest run src/pages/AIAssistant/ragSources.test.ts src/services/aiAssistantService.test.ts
```

- [x] **Step 6: Commit**

```powershell
git -C ..\prohelper_admin add src/types/aiAssistant.ts src/services/aiAssistantService.ts src/pages/AIAssistant/AIAssistantChatPage.tsx src/pages/AIAssistant/ragSources.ts src/pages/AIAssistant/ragSources.test.ts src/services/aiAssistantService.test.ts
git -C ..\prohelper_admin commit -m "feat[lk]: показаны источники RAG в AI-ассистенте"
```

## Task 8: Add Remaining MVP Sources

**Files:**
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ProcurementRagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/WarehouseRagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/SiteRequestRagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/WorkCompletionRagSource.php`
- Create: `app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/ProjectPulseRagSource.php`
- Modify: `app/BusinessModules/Features/AIAssistant/AIAssistantServiceProvider.php`
- Test: `tests/Unit/AIAssistant/Rag/RagSourceCollectorsTest.php`

- [x] **Step 1: Write collector tests**

For each collector, assert:

- every query scopes by organization
- optional project filter is applied
- collector returns human-readable content
- metadata contains source type and entity refs

- [x] **Step 2: Implement collectors**

Keep each collector read-only. Use existing models/services where available. Avoid raw SQL unless local module already uses it.

- [x] **Step 3: Register collectors**

Add all new collectors to `RagSourceRegistry`.

- [x] **Step 4: Run tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant\Rag\RagSourceCollectorsTest.php
vendor\bin\phpunit tests\Unit\AIAssistant\Rag
```

- [x] **Step 5: Commit**

```powershell
git add app/BusinessModules/Features/AIAssistant/Services/Rag tests/Unit/AIAssistant/Rag
git commit -m "feat[backend]: расширены источники RAG для проектных данных"
```

## Task 9: Final Verification

**Files:**
- No new files expected.

- [x] **Step 1: Backend syntax**

```powershell
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever.php
php -l app\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer.php
php -l app\BusinessModules\Features\AIAssistant\Services\AIAssistantService.php
```

- [x] **Step 2: Backend tests**

```powershell
vendor\bin\phpunit tests\Unit\AIAssistant tests\Feature\Api\V1\Admin\AIAssistantRagContextTest.php
vendor\bin\phpunit tests\Feature\Console\AIAssistantRagBackfillCommandTest.php
vendor\bin\phpstan analyse app/BusinessModules/Features/AIAssistant tests/Unit/AIAssistant tests/Feature/Api/V1/Admin/AIAssistantRagContextTest.php tests/Feature/Console/AIAssistantRagBackfillCommandTest.php --memory-limit=1G
```

- [x] **Step 3: Admin tests**

```powershell
cd ..\prohelper_admin
npx tsc --noEmit
npx vitest run src/services/aiAssistantService.test.ts
cd ..\prohelper
```

- [ ] **Step 4: Manual staging checklist**

Do not run migrations locally unless explicitly requested. On staging after migration:

- Enable `AI_RAG_ENABLED=true`.
- Backfill one organization.
- Ask assistant a project-risk question.
- Confirm answer includes sources.
- Confirm inaccessible project chunks are not returned.
- Confirm no source block appears when no context is used.

Staging evidence template: `docs/reports/ai-assistant-rag-staging-evidence-template.md`.
Final local report: `docs/reports/ai-assistant-rag-mvp-completion.md`.

- [x] **Step 5: Final commit if verification fixes were needed**

```powershell
git status --short
git commit -m "test[backend]: проверен MVP RAG для AI-ассистента"
```

## Acceptance Criteria

- RAG can be disabled by config without changing existing assistant behavior.
- Indexed chunks are organization-scoped and optionally project-scoped.
- Retrieval applies authorization before context enters the prompt.
- Assistant metadata exposes `rag_context` without breaking existing admin/mobile clients.
- UI shows sources only for grounded answers.
- Backend controllers and services use `trans_message('ai_assistant.*')` for user-facing errors and fallback messages.
- No mojibake in touched backend/admin files.
- No migrations, dev servers or forbidden admin/land builds are run by agents during implementation.

## Execution Recommendation

Use subagent-driven execution:

1. Backend schema and embeddings subagent.
2. Backend retrieval and assistant integration subagent.
3. Admin contract/rendering subagent.
4. Review subagent for permissions and API contracts.
5. Review subagent for encoding and test coverage.
