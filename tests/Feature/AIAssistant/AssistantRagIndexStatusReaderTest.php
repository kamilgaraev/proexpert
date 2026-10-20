<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\GetRagIndexStatusTool;
use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshAssistantIndexStatusJob;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Operational\AssistantRagIndexStatusReader;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\Project;
use App\Services\Credits\AICreditService;
use App\Services\Modules\PackageCatalogService;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class AssistantRagIndexStatusReaderTest extends TestCase
{
    private const QUERY = 'Какие данные по всей нашей организации уже доступны тебе для поиска? Проверь состояние базы знаний и очереди индексации, покажи подтверждённые количества документов и фрагментов. Если статистика пока не готова, прямо скажи. Ничего не переиндексируй.';

    private AssistantRealAuthorizationFixture $fixture;

    private QueueFake $queue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'ai-assistant.status_snapshots' => true,
            'ai-assistant.status_snapshot_release' => str_repeat('a', 40)]);
        $this->queue = Queue::fake();
        Cache::flush();
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        app(AssistantDataAccessPolicy::class)->setTrustedSurface(KnowledgeSurface::ADMIN);
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        parent::tearDown();
    }

    public function test_cold_status_reports_unknown_counts_without_claiming_zero_or_reindexing(): void
    {
        $read = $this->read();
        self::assertFalse($read['data']['status_available']);
        self::assertStringContainsString('сейчас недоступна', $read['server_formatted_answer']);
        self::assertStringContainsString('не запускает переиндексацию', $read['server_formatted_answer']);
        self::assertArrayNotHasKey('source_count', $read['data']);
        Queue::assertPushed(RefreshAssistantIndexStatusJob::class, 1);
        self::assertSame(0, RagIndexRun::query()->count());
    }

    public function test_ready_projection_matches_native_status_and_current_database_permissions(): void
    {
        $own = (int) $this->fixture->organization->id;
        $this->source($own);
        $this->source((int) $this->fixture->foreignOrganization->id);
        $before = [RagSource::query()->count(), RagChunk::query()->count(), RagIndexRun::query()->count()];
        $this->warm();
        $raw = app(AssistantIndexStatusService::class)->status($own, $this->fixture->owner, 'all');
        self::assertTrue($raw['status_available']);
        $native = (new RagIndexStatusResource($raw))->toArray(request());
        $read = $this->read();
        self::assertTrue($read['data']['status_available']);
        self::assertSame(1, $read['data']['source_count']);
        foreach (['source_count', 'chunk_count', 'expected_source_count', 'indexed_source_count', 'stored_source_count', 'stale_source_count', 'pending_source_count'] as $field) {
            self::assertSame($raw[$field] ?? null, $read['data'][$field], $field);
        }
        foreach ($read['data']['documents'] as $field => $count) {
            self::assertSame($native['document_coverage'][$field] ?? null, $count, $field);
        }
        self::assertStringContainsString('Число источников не равно числу файлов', $read['server_formatted_answer']);
        self::assertStringContainsString('полнота охвата оценивается отдельно', $read['server_formatted_answer']);
        self::assertSame($before, [RagSource::query()->count(), RagChunk::query()->count(), RagIndexRun::query()->count()]);
        self::assertStringNotContainsString('SECRET-INDEX-CONTENT', $read['server_formatted_answer']);
        $guard = app(AssistantSourceReferenceGuard::class);
        self::assertTrue($guard->fresh($this->fixture->owner, $own, $read['source_refs']));
        $this->fixture->owner->organizations()->updateExistingPivot($own, ['is_active' => false]);
        self::assertFalse($guard->fresh($this->fixture->owner, $own, $read['source_refs']));
        $this->expectException(AuthorizationException::class);
        $this->read();
    }

    public function test_registered_tool_obeys_actual_assistant_permission_on_exposure_and_execution(): void
    {
        $checker = app(AIPermissionChecker::class);
        $name = GetRagIndexStatusTool::NAME;
        self::assertTrue($checker->hasExplicitToolPolicy($name));
        self::assertTrue($checker->canExposeTool($this->fixture->owner, $name));
        self::assertTrue($checker->canExecuteTool($this->fixture->owner, $name, ['scope' => 'organization']));
        self::assertInstanceOf(GetRagIndexStatusTool::class, app(AIToolRegistry::class)->getTool($name));
        $this->fixture->memberRole->update(['module_permissions' => ['project-management' => ['projects.view']]]);
        self::assertFalse($checker->canExposeTool($this->fixture->member, $name));
        self::assertFalse($checker->canExecuteTool($this->fixture->member, $name, ['scope' => 'organization']));
    }

    public function test_native_status_is_current_inside_a_writable_completion_transaction_in_production_mode(): void
    {
        $own = (int) $this->fixture->organization->id;
        $service = app(AssistantIndexStatusService::class);
        $environment = app()->environment();
        $searchPath = DB::selectOne("SELECT current_setting('search_path') AS value")->value;
        app()->instance('env', 'production');
        try {
            DB::selectOne("SELECT set_config('search_path', 'public', false)");
            $this->source($own);
            $this->warm();
            self::assertTrue($service->status($own, $this->fixture->owner, 'all')['status_available']);
            $metrics = new ApiQueryMetrics(true);
            request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
            $default = DB::getDefaultConnection();
            $connections = array_keys(DB::getConnections());
            $outerPid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
            DB::transaction(function () use ($service, $own, $metrics, $default, $outerPid): void {
                DB::select('SELECT 1');
                $status = $service->status($own, $this->fixture->owner, 'all');
                self::assertTrue($status['status_available'], json_encode(array_intersect_key($metrics->summary(), array_flip(['assistant_snapshot', 'assistant_snapshot_epoch']))));
                self::assertSame('off', DB::selectOne("SELECT current_setting('transaction_read_only') AS mode")->mode);
                self::assertSame($default, DB::getDefaultConnection());
                self::assertSame($outerPid, DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                self::assertSame(1, DB::transactionLevel());
            });
            self::assertSame($connections, array_keys(DB::getConnections()));
        } finally {
            DB::selectOne("SELECT set_config('search_path', ?, false)", [$searchPath]);
            app()->instance('env', $environment);
        }
    }

    public function test_invalidated_real_snapshot_reports_unknown_and_queues_refresh_after_outer_commit(): void
    {
        $own = (int) $this->fixture->organization->id;
        $this->source($own);
        $key = $this->warm();
        $snapshot = Cache::get($key);
        $snapshot['fingerprint'] = str_repeat('0', 64);
        Cache::put($key, $snapshot, 60);
        $this->queue = Queue::fake();
        $outer = DB::connection();
        $outer->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager);
        try {
            DB::transaction(function () use ($own): void {
                $status = app(AssistantIndexStatusService::class)->status($own, $this->fixture->owner, 'all');
                self::assertFalse($status['status_available']);
                Queue::assertNothingPushed();
            });
        } finally {
            $outer->setTransactionManager(app('db.transactions'));
        }
        Queue::assertPushed(RefreshAssistantIndexStatusJob::class, 1);
        self::assertArrayNotHasKey('assistant_status_snapshot_read', DB::getConnections());
    }

    #[DataProvider('readinessModes')]
    public function test_original_question_completes_with_real_index_status_despite_ui_project_and_no_document_corpus(bool $warm): void
    {
        $own = (int) $this->fixture->organization->id;
        $this->source($own);
        $before = [RagSource::query()->count(), RagChunk::query()->count(), RagIndexRun::query()->count()];
        config(['ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true]);
        $actor = $this->fixture->owner;
        $org = $this->fixture->organization;
        $this->actingAs($actor);
        app(AICreditService::class)->grant($org, 100000, 'purchase', null, 'index-status-'.Str::uuid());
        $ui = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $own, 'is_archived' => false]));
        if ($warm) {
            $this->warm();
        }
        $calls = 0;
        $provider = $this->createMock(IndexStatusResponsesProvider::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('countTokens')->willReturn(100);
        $provider->expects(self::never())->method('chat');
        $provider->expects(self::atLeastOnce())->method('responses')->willReturnCallback(static function (array $input, array $options) use (&$calls): array {
            self::assertLessThanOrEqual(2, ++$calls);
            self::assertContains(GetRagIndexStatusTool::NAME, array_column($options['tools'] ?? [], 'name'));
            $output = [];
            if ($calls === 1) {
                self::assertSame(['type' => 'function', 'function' => ['name' => GetRagIndexStatusTool::NAME]], $options['tool_choice'] ?? null);
                $output[] = ['type' => 'function_call', 'id' => 'index-status-read', 'call_id' => 'index-status-read', 'status' => 'completed',
                    'name' => GetRagIndexStatusTool::NAME, 'arguments' => '{"scope":"organization"}'];
            }

            return ['content' => $calls === 1 ? '' : 'Непроверенный текст модели.', 'output' => $output, 'function_calls' => $output,
                'input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'openai', 'model' => 'gpt-6-luna', 'response_status' => 'completed'];
        });
        $payload = ['request_id' => (string) Str::uuid(), 'message' => self::QUERY, 'profile' => 'normal', 'allow_actions' => false,
            'context' => ['entity_refs' => [['type' => 'project', 'id' => $ui->id]]]];
        $payload['quote_id'] = app(AICreditService::class)->quote($org, $actor, $payload)['quote_id'];
        $metrics = new ApiQueryMetrics(true);
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        try {
            $response = $this->app->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ragRetriever' => null])->ask(self::QUERY, $own, $actor, null, $payload);
        } catch (\Throwable $exception) {
            self::fail($exception::class.' '.json_encode(array_intersect_key($metrics->summary(), array_flip(['assistant_snapshot', 'assistant_snapshot_epoch']))));
        }
        self::assertSame('completed', AssistantRequest::query()->where('request_id', $response['request_id'])->sole()->status);
        $metadata = $response['message']['metadata'];
        self::assertFalse($metadata['needs_clarification']);
        self::assertSame([], $metadata['artifacts']);
        self::assertSame([], $metadata['telemetry']['tool_failures']);
        self::assertCount(1, $metadata['source_refs']);
        self::assertSame(AssistantRagIndexStatusReader::TYPE, $metadata['source_refs'][0]['entity_type']);
        self::assertSame($own, $metadata['source_refs'][0]['organization_id']);
        self::assertNull($metadata['source_refs'][0]['project_id']);
        if ($warm) {
            self::assertStringContainsString('Источников в поисковом индексе: 1', $response['message']['content']);
        } else {
            self::assertStringContainsString('сейчас недоступна', $response['message']['content']);
            self::assertStringNotContainsString('Источников в поисковом индексе: 0', $response['message']['content']);
        }
        self::assertStringContainsString('не запускает переиндексацию', $response['message']['content']);
        self::assertStringNotContainsString('Непроверенный текст модели', $response['message']['content']);
        self::assertStringNotContainsString('SECRET-INDEX-CONTENT', $response['message']['content']);
        self::assertSame($before, [RagSource::query()->count(), RagChunk::query()->count(), RagIndexRun::query()->count()]);
        self::assertArrayNotHasKey('assistant_status_snapshot_read', DB::getConnections());
    }

    public static function readinessModes(): array
    {
        return ['ready' => [true], 'unknown' => [false]];
    }

    private function read(): array
    {
        return app(AssistantRagIndexStatusReader::class)->read($this->fixture->owner,
            (int) $this->fixture->organization->id, ['scope' => 'organization']);
    }

    private function warm(): string
    {
        $service = app(AssistantIndexStatusService::class);
        self::assertFalse($service->status((int) $this->fixture->organization->id, $this->fixture->owner, 'all')['status_available']);
        $job = $this->queue->pushed(RefreshAssistantIndexStatusJob::class)->last();
        self::assertInstanceOf(RefreshAssistantIndexStatusJob::class, $job);
        self::assertSame((int) $this->fixture->organization->id, $job->organizationId);
        self::assertSame((int) $this->fixture->owner->id, $job->actorId);
        self::assertSame(KnowledgeSurface::ADMIN, $job->surface);
        self::assertSame('all', $job->section);
        $job->handle($service, app(AssistantDataAccessPolicy::class));
        $snapshot = Cache::get($job->cacheKey);
        self::assertIsArray($snapshot);
        foreach (['status', 'fingerprint', 'valid_until', 'projection_generation', 'epoch', 'decisions'] as $field) {
            self::assertArrayHasKey($field, $snapshot);
        }
        self::assertTrue($service->status((int) $this->fixture->organization->id, $this->fixture->owner, 'all')['status_available']);

        return $job->cacheKey;
    }

    private function source(int $organizationId): void
    {
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $organizationId, 'is_archived' => false]));
        $source = RagSource::query()->create(['organization_id' => $organizationId, 'project_id' => $project->id,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $project->id,
            'title' => 'SECRET-INDEX-CONTENT', 'checksum' => hash('sha256', 'project-'.$project->id)]);
        $chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $organizationId,
            'project_id' => $project->id, 'chunk_index' => 0, 'content' => 'SECRET-INDEX-CONTENT', 'content_hash' => $source->checksum]);
        DB::update('UPDATE ai_rag_chunks SET embedding = ?::vector WHERE id = ?', ['['.implode(',', RagTestEmbedding::fromLeadingValues([0.01])).']', $chunk->id]);
    }
}

interface IndexStatusResponsesProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}
