<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\File;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantAdmittedRetrievalCheckpointProfileTest extends TestCase
{
    private const TAIL = 'AFTER_340_CHECKPOINT_PROFILE';

    private mixed $originalRedis = null;
    private ?AssistantRequestExecutionContext $execution = null;
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $output = getenv('MOST_ASSISTANT_CHECKPOINT_PROFILE_OUTPUT');
        if (! is_string($output) || $output === '') {
            self::markTestSkipped('Explicit external profiling artifact path is required.');
        }
        $directory = realpath(dirname($output));
        $checkout = realpath(base_path());
        self::assertIsString($directory);
        self::assertIsString($checkout);
        $normalizedDirectory = strtolower(str_replace('\\', '/', $directory));
        $normalizedCheckout = strtolower(str_replace('\\', '/', $checkout));
        self::assertNotSame($normalizedCheckout, $normalizedDirectory);
        self::assertFalse(str_starts_with($normalizedDirectory.'/', $normalizedCheckout.'/'));
        self::assertSame('json', pathinfo($output, PATHINFO_EXTENSION));
        self::assertFileDoesNotExist($output);
        $this->output = $output;
        Carbon::setTestNow('2026-10-24 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true,
            'ai-assistant-credits.enforce' => true]);
        $this->originalRedis = Redis::getFacadeRoot();
        Redis::swap(new class
        {
            public function connection(?string $name = null): object
            {
                return new class
                {
                    public function eval(mixed ...$arguments): int
                    {
                        return 1;
                    }
                };
            }
        });
    }

    protected function tearDown(): void
    {
        $this->execution?->restoreDatabaseStatementTimeouts();
        $this->app->forgetInstance(AssistantRequestExecutionContext::class);
        if ($this->originalRedis !== null) { Redis::swap($this->originalRedis); }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_profiles_real_checkpoint_cost_for_one_authorized_fragment_without_network(): void
    {
        $fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $fixture->organization->id, 'is_archived' => false]));
        $prefix = 'QA-CHECK-001 '.str_repeat('x', 360).' ';
        $text = $prefix.str_repeat('z', 504 - mb_strlen($prefix.self::TAIL)).self::TAIL;
        $file = Model::withoutEvents(fn () => File::query()->create(['organization_id' => $fixture->organization->id,
            'user_id' => $fixture->owner->id, 'fileable_type' => $project->getMorphClass(), 'fileable_id' => $project->id,
            'name' => 'Checkpoint fixture.txt', 'original_name' => 'Checkpoint fixture.txt', 'path' => 'private/checkpoint.txt',
            'disk' => 's3', 'mime_type' => 'text/plain', 'size' => strlen($text)]));
        $document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $fixture->organization->id, 'project_id' => $project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $project->id, 'storage_path' => $file->path,
            'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', $text),
            'size_bytes' => strlen($text), 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(),
            'extracted_text' => $text, 'metadata' => []]));
        $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $document->id, 'unit_type' => 'page',
            'unit_index' => 0, 'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => 1]]);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $data = FileRagSource::chunkForUnit($document, $unit);
        $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title,
            'source_version' => now()->toAtomString(), 'metadata' => $data->metadata, 'indexed_at' => now()]);
        $chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId,
            'project_id' => $data->projectId, 'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
            'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1]]);
        $this->actingAs($fixture->owner);
        $policy = app(AssistantDataAccessPolicy::class);
        $policy->setTrustedSurface(KnowledgeSurface::ADMIN);
        $conversation = Conversation::query()->create(['organization_id' => $fixture->organization->id,
            'user_id' => $fixture->owner->id, 'title' => 'Synthetic checkpoint profile']);
        $credits = app(AICreditService::class);
        $credits->grant($fixture->organization, 100000, 'purchase', null, 'checkpoint-profile-'.Str::uuid());
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'QA-CHECK-001',
            'conversation_id' => $conversation->id, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = $credits->quote($fixture->organization, $fixture->owner, $payload)['quote_id'];
        $lifecycle = app(AssistantRequestLifecycle::class);
        $started = $lifecycle->startQueued($fixture->organization, $fixture->owner, $conversation->id, $payload, 'admin');
        $request = $lifecycle->claimQueued($started['request']->id);
        self::assertNotNull($request);
        self::assertSame('running', $request->status);
        self::assertNotNull($request->started_at);
        self::assertSame($conversation->id, $request->conversation_id);
        self::assertFalse($this->app->bound(AssistantRequestExecutionContext::class));
        $this->execution = $lifecycle->createExecutionContext($request, $fixture->owner);
        $this->app->instance(AssistantRequestExecutionContext::class, $this->execution);
        $this->execution->activate();
        $this->execution->assertCanContinue();
        self::assertSame($this->execution, app(AssistantRequestExecutionContext::class));
        $profile = ['schema' => 'admitted-retrieval-checkpoint-profile-v1', 'component_only' => true,
            'redis_admission' => 'synthetic always-admit connector; real limiter/checkpoint code retained; no contention measurement',
            'cache_store' => 'array', 'provider_calls' => 0, 'embedding_calls' => 0,
            'checkpoint_calls' => 0, 'checkpoint_successes' => 0, 'checkpoint_wall_ms' => 0.0,
            'checkpoint_max_depth' => 0, 'checkpoint_sites' => [], 'checkpoint_sql_count_histogram' => [],
            'sql_listener_wall_ms' => 0.0,
            'sql' => ['checkpoint' => ['count' => 0, 'reported_ms' => 0.0],
                'coverage' => ['count' => 0, 'reported_ms' => 0.0], 'retrieval' => ['count' => 0, 'reported_ms' => 0.0]],
            'stage_wall_ms' => [], 'cancellation_observed' => false];
        $recording = false;
        $stage = 'coverage';
        $checkpointDepth = 0;
        DB::listen(static function (QueryExecuted $event) use (&$profile, &$recording, &$stage, &$checkpointDepth): void {
            if (! $recording) { return; }
            $observerStarted = hrtime(true);
            $bucket = $checkpointDepth > 0 ? 'checkpoint' : $stage;
            $profile['sql'][$bucket]['count']++;
            $profile['sql'][$bucket]['reported_ms'] += $event->time;
            $profile['sql_listener_wall_ms'] += (hrtime(true) - $observerStarted) / 1_000_000;
        });
        $knownSources = [];
        foreach (['Rag/RagRetriever', 'AssistantReadConcurrencyLimiter', 'AssistantDataAccessPolicy',
            'AssistantRequestExecutionContext', 'AssistantSourceReferenceGuard'] as $sourceName) {
            $knownSources[str_replace('\\', '/', base_path('app/BusinessModules/Features/AIAssistant/Services/'.$sourceName.'.php'))] = basename($sourceName);
        }
        $checkpoint = function () use (&$profile, &$checkpointDepth, $knownSources): void {
            $parts = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
                $file = str_replace('\\', '/', $frame['file'] ?? '');
                if (! isset($knownSources[$file]) || ! isset($frame['line'])) { break; }
                $parts[] = $knownSources[$file].':'.$frame['line'];
                if (count($parts) === 3) { break; }
            }
            $site = $parts === [] ? 'other' : implode('>', $parts);
            if (! isset($profile['checkpoint_sites'][$site]) && count($profile['checkpoint_sites']) >= 31) { $site = 'other'; }
            $profile['checkpoint_sites'][$site] ??= ['calls' => 0, 'wall_ms' => 0.0, 'sql_count' => 0, 'sql_reported_ms' => 0.0];
            $profile['checkpoint_sites'][$site]['calls']++;
            $sqlStarted = $profile['sql']['checkpoint'];
            $profile['checkpoint_calls']++;
            $checkpointDepth++;
            $profile['checkpoint_max_depth'] = max($profile['checkpoint_max_depth'], $checkpointDepth);
            $started = hrtime(true);
            try {
                $this->execution->assertCanContinue();
                $profile['checkpoint_successes']++;
            } finally {
                $wall = (hrtime(true) - $started) / 1_000_000;
                if ($checkpointDepth === 1) { $profile['checkpoint_wall_ms'] += $wall; }
                $sqlCount = $profile['sql']['checkpoint']['count'] - $sqlStarted['count'];
                $profile['checkpoint_sites'][$site]['wall_ms'] += $wall;
                $profile['checkpoint_sites'][$site]['sql_count'] += $sqlCount;
                $profile['checkpoint_sites'][$site]['sql_reported_ms'] += $profile['sql']['checkpoint']['reported_ms'] - $sqlStarted['reported_ms'];
                $histogramKey = (string) $sqlCount;
                if (! isset($profile['checkpoint_sql_count_histogram'][$histogramKey]) && count($profile['checkpoint_sql_count_histogram']) >= 31) { $histogramKey = 'other'; }
                $profile['checkpoint_sql_count_histogram'][$histogramKey] = ($profile['checkpoint_sql_count_histogram'][$histogramKey] ?? 0) + 1;
                $checkpointDepth--;
            }
        };
        $projection = app(OrdinaryAssistantDocumentProjection::class);
        $retriever = app(RagRetriever::class);
        $limiter = (new \ReflectionObject($retriever))->getProperty('readLimiter')->getValue($retriever);
        $profile['read_limiter_class'] = is_object($limiter) ? $limiter::class : null;
        $profileStarted = hrtime(true);
        $capture = $search = $projected = null;
        try {
            $recording = true;
            $started = hrtime(true);
            $capture = $projection->capture($fixture->owner, $fixture->organization->id, $project->id,
                ['project_id' => $project->id, 'document_id' => $document->id, 'chunk_ids' => [$chunk->id]]);
            $profile['stage_wall_ms']['capture'] = (hrtime(true) - $started) / 1_000_000;
            $stage = 'retrieval';
            $started = hrtime(true);
            $search = $retriever->searchWithDiagnostics('QA-CHECK-001', $fixture->organization->id,
                $fixture->owner, ['project_id' => $project->id, 'source_types' => ['file_document'],
                    'limit' => 1, 'retrieval_mode' => 'lexical'], $checkpoint, corpus: $capture['corpus']);
            $profile['stage_wall_ms']['retrieval'] = (hrtime(true) - $started) / 1_000_000;
            $stage = 'coverage';
            $started = hrtime(true);
            $projected = $projection->project($capture['corpus'], $search['results'], $fixture->owner, $fixture->organization->id);
            $profile['stage_wall_ms']['projection'] = (hrtime(true) - $started) / 1_000_000;
        } catch (\Throwable $failure) {
            $profile['failure_class'] = $failure::class;
            throw $failure;
        } finally {
            $recording = false;
            $profile['profile_wall_ms'] = (hrtime(true) - $profileStarted) / 1_000_000;
            $profile['result_count'] = is_array($search) ? count($search['results']) : null;
            $profile['selected_content_chars'] = is_array($projected) ? mb_strlen($projected['data']['fragments'][0]['content'] ?? '') : null;
            file_put_contents($this->output, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        self::assertNotNull($capture);
        self::assertNotNull($search);
        self::assertNotNull($projected);
        self::assertCount(1, $search['results']);
        self::assertSame($text, $search['results'][0]->providerContent());
        self::assertSame($chunk->id, $search['results'][0]->metadata['chunk_id']);
        self::assertCount(1, $projected['data']['fragments']);
        self::assertSame($text, $projected['data']['fragments'][0]['content']);
        self::assertSame($chunk->id, $projected['data']['fragments'][0]['chunk_id']);
        self::assertSame(504, mb_strlen($projected['data']['fragments'][0]['content']));
        self::assertGreaterThan(340, mb_strpos($projected['data']['fragments'][0]['content'], self::TAIL));
        self::assertSame($capture['preview']['data']['fragments'], $projected['data']['fragments']);
        self::assertFalse($projected['data']['fragments'][0]['content_range']['truncated']);
        self::assertGreaterThan(0, $profile['checkpoint_calls']);
        self::assertSame($profile['checkpoint_calls'], $profile['checkpoint_successes']);
        self::assertGreaterThan(0, $profile['sql']['checkpoint']['count']);
        $request->update(['cancel_requested_at' => now()]);
        try {
            $this->execution->assertCanContinue();
            self::fail('The real lifecycle checkpoint must detect current cancellation.');
        } catch (AssistantRequestCancelled) {
            $profile['cancellation_observed'] = true;
        }
        file_put_contents($this->output, json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
