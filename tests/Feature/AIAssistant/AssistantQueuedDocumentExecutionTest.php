<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\ConversationParticipant;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use Closure;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;
use Throwable;

final class AssistantQueuedDocumentExecutionTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private User $operator;
    private AIAssistantDocument $document;
    private array $chunks = [];
    private OrdinaryAssistantEnrollmentService $enrollments;
    private ?Closure $wireObserver = null;
    private ?float $jobDurationMs = null;
    private const SENTINEL = 'APPROVED_FRAGMENT_SENTINEL';
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';
    private const ANSWER = 'В утверждённом фрагменте указано 17 тонн арматуры.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
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
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true, 'ai-assistant-credits.enforce' => true,
            'ai-assistant-credits.profiles.short' => ['input_tokens' => 12288, 'output_tokens' => 1024, 'max_calls' => 3],
            'ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $this->operator = User::factory()->create(['is_active' => true]);
        UserRoleAssignment::query()->create(['user_id' => $this->operator->id, 'role_slug' => 'system_admin',
            'role_type' => UserRoleAssignment::TYPE_SYSTEM, 'context_id' => AuthorizationContext::getSystemContext()->id,
            'assigned_by' => $this->operator->id, 'is_active' => true]);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->method('provider')->willReturn('offline');
        $provider->method('model')->willReturn('offline');
        $provider->method('dimensions')->willReturn(256);
        $provider->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);
        $file = Model::withoutEvents(fn () => File::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'fileable_type' => $this->project->getMorphClass(), 'fileable_id' => $this->project->id,
            'name' => 'Учебный документ.txt', 'original_name' => 'Учебный документ.txt', 'path' => 'private/'.$this->project->id.'/sample.txt',
            'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 100]));
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create(['organization_id' => $this->fixture->organization->id,
            'project_id' => $this->project->id, 'file_id' => $file->id, 'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->project->id,
            'storage_path' => $file->path, 'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', 'document'),
            'size_bytes' => 100, 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(), 'extracted_text' => 'Учебный текст', 'metadata' => []]));
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        foreach (['бетон '.self::SENTINEL, 'арматура: '.str_repeat('учебные сведения ', 32).'Утверждённый объём: 17 тонн '.self::SENTINEL, 'КС-2 %_\\ буквальный код', 'НЕУТВЕРЖДЕННЫЙ бетон бетон бетон'] as $number => $text) {
            $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page', 'unit_index' => $number,
                'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => $number + 1]]);
            $data = FileRagSource::chunkForUnit($this->document, $unit);
            $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title, 'source_version' => now()->toAtomString(),
                'metadata' => $data->metadata, 'indexed_at' => now()]);
            $this->chunks[] = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId, 'project_id' => $data->projectId,
                'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
                'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1], 'embedding_provider' => 'offline', 'embedding_model' => 'offline']);
        }
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'document-admission-'.Str::uuid());
        DB::commit();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (DB::transactionLevel() === 0) { DB::beginTransaction(); }
        parent::tearDown();
    }

    public function test_actual_queued_job_executes_the_approved_document_search_and_completes_the_native_loop(): void
    {
        $this->runQueuedDocument();
    }

    #[DataProvider('revocations')]
    public function test_warmed_queued_authorization_observes_revocation_before_the_second_send(string $revocation): void
    {
        $this->runQueuedDocument($revocation);
    }

    public static function revocations(): iterable
    {
        yield 'assistant permission' => ['assistant'];
        yield 'conversation becomes read only' => ['conversation'];
    }

    public function test_each_native_dispatch_checks_the_current_source_at_prepare_and_at_the_actual_transport(): void
    {
        $pending = 0;
        $checks = [];
        $delays = [];
        DB::listen(static function (QueryExecuted $query) use (&$pending): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'assistant_coverage_documents') || ! str_contains($sql, 'expected_units')
                || ! str_contains($sql, 'current_units') || ! str_contains($sql, 'partial_documents')) {
                return;
            }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                if (($frame['class'] ?? null) === OrdinaryAssistantScopedContext::class && ($frame['function'] ?? null) === 'authorize') {
                    $pending++;
                    return;
                }
            }
        });
        $this->wireObserver = static function () use (&$pending, &$checks, &$delays): void {
            $checks[] = $pending;
            $pending = 0;
            $started = hrtime(true);
            usleep(100_000);
            $delays[] = (hrtime(true) - $started) / 1_000_000;
        };
        $this->runQueuedDocument();
        self::assertSame([2, 2], $checks);
        self::assertCount(2, $delays);
        foreach ($delays as $delay) { self::assertGreaterThan(0, $delay); }
        self::assertNotNull($this->jobDurationMs);
        self::assertGreaterThanOrEqual(200, $this->jobDurationMs);
        fwrite(STDERR, 'QUEUED_DISPATCH_REGRESSION '.json_encode(['source_current_checks' => $checks,
            'sdk_mock_delays_ms' => array_map(static fn (float $delay): float => round($delay, 2), $delays),
            'actual_job_ms' => round($this->jobDurationMs, 2)], JSON_THROW_ON_ERROR).PHP_EOL);
    }

    #[DataProvider('preWireRevocations')]
    public function test_current_revocation_after_provider_entry_blocks_the_actual_handler(string $revocation): void
    {
        $this->runQueuedDocument(beforeWireRevocation: $revocation);
    }

    public static function preWireRevocations(): iterable
    {
        yield 'cohort generation' => ['cohort'];
        yield 'approved source' => ['source'];
        yield 'actor permission' => ['actor'];
    }

    private function runQueuedDocument(?string $revocation = null, ?string $beforeWireRevocation = null): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $beforeWireFired = false;
        if ($beforeWireRevocation !== null) {
            DB::listen(function (QueryExecuted $query) use ($beforeWireRevocation, $cohort, &$beforeWireFired): void {
                if ($beforeWireFired || ! str_contains(strtolower($query->sql), 'from "ai_assistant_requests"')) { return; }
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96) as $frame) {
                    if (($frame['class'] ?? null) !== TimewebProvider::class || ($frame['function'] ?? null) !== 'responses') { continue; }
                    $beforeWireFired = true;
                    self::assertTrue(app(OrdinaryAssistantScopedContext::class)->isGuarded());
                    self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                    if ($beforeWireRevocation === 'cohort') {
                        $cohort->newQuery()->whereKey($cohort->id)->update(['generation' => (string) Str::uuid()]);
                    } elseif ($beforeWireRevocation === 'source') {
                        $this->chunks[1]->newQuery()->whereKey($this->chunks[1]->id)->update(['content' => 'Изменённый утверждённый фрагмент',
                            'content_hash' => hash('sha256', 'Изменённый утверждённый фрагмент')]);
                    } else {
                        UserRoleAssignment::query()->whereKey($this->fixture->ownerAssignment->id)->update(['is_active' => false]);
                    }
                    return;
                }
            });
        }
        self::assertGreaterThan(340, mb_strpos($manifest['sources'][0]['projection']['data']['fragments'][1]['content'], '17 тонн'));
        $registry = app(AIToolRegistry::class);
        $tool = $registry->getTool(OrdinaryAssistantDocumentProjection::TOOL);
        self::assertInstanceOf(SearchAssistantDocumentsTool::class, $tool);
        $afterRead = $revocation === null ? null : function () use ($cohort, $payload, $revocation): void {
            $checker = app(AIPermissionChecker::class);
            self::assertTrue($checker->canUseAssistant($this->fixture->owner, $cohort->organization_id));
            if ($revocation === 'assistant') {
                UserRoleAssignment::query()->whereKey($this->fixture->ownerAssignment->id)->update(['is_active' => false]);
                self::assertFalse($checker->canUseAssistant($this->fixture->owner, $cohort->organization_id));
                return;
            }
            $current = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
            self::assertNotNull($current->conversation_id);
            Conversation::query()->whereKey($current->conversation_id)->update(['user_id' => $this->fixture->member->id]);
            ConversationParticipant::query()->where('conversation_id', $current->conversation_id)
                ->where('user_id', $this->fixture->owner->id)->update(['role' => 'viewer']);
            $manager = app(ConversationManager::class);
            self::assertNotNull($manager->findAccessibleConversation($current->conversation_id, $this->fixture->owner, $cohort->organization_id));
            self::assertNull($manager->findAccessibleConversation($current->conversation_id, $this->fixture->owner, $cohort->organization_id, true));
        };
        $probe = new class($tool, $afterRead) implements AIToolInterface {
            public ?Throwable $failure = null;
            public int $executions = 0;
            public function __construct(private readonly AIToolInterface $delegate, private readonly ?Closure $afterRead) {}
            public function getName(): string { return $this->delegate->getName(); }
            public function getDescription(): string { return $this->delegate->getDescription(); }
            public function getParametersSchema(): array { return $this->delegate->getParametersSchema(); }
            public function execute(array $arguments, ?User $user, Organization $organization): array|string
            {
                $this->executions++;
                try {
                    $result = $this->delegate->execute($arguments, $user, $organization);
                    if ($this->afterRead !== null) { ($this->afterRead)(); }
                    return $result;
                }
                catch (Throwable $failure) { $this->failure = $failure; throw $failure; }
            }
        };
        $registry->registerTool($probe);
        $wire = [];
        $mock = new MockHandler([
            function (RequestInterface $request) use (&$wire): Response {
                $this->wireObserver?->__invoke();
                self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                self::assertInstanceOf(AssistantRequestExecutionContext::class, app(AssistantRequestExecutionContext::class));
                self::assertStringNotContainsString(self::ACCOUNT, (string) $request->getBody());
                $wire[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                self::assertSame('auto', $wire[0]['tool_choice']);
                self::assertSame([OrdinaryAssistantDocumentProjection::TOOL], array_column($wire[0]['tools'], 'name'));
                self::assertCount(0, array_filter($wire[0]['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
                return $this->sdkResponse('resp_queued_document_1', [['type' => 'function_call', 'id' => 'fc_queued_document_1',
                    'call_id' => 'call_queued_document_1', 'status' => 'completed', 'name' => OrdinaryAssistantDocumentProjection::TOOL,
                    'arguments' => json_encode($this->args('арматура'), JSON_THROW_ON_ERROR)]]);
            },
            function (RequestInterface $request) use (&$wire, $manifest): Response {
                $this->wireObserver?->__invoke();
                self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                self::assertStringNotContainsString(self::ACCOUNT, (string) $request->getBody());
                $wire[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $calls = array_values(array_filter($wire[1]['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
                $outputs = array_values(array_filter($wire[1]['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
                self::assertCount(1, $calls);
                self::assertCount(1, $outputs);
                self::assertSame('fc_queued_document_1', $calls[0]['id']);
                self::assertSame('call_queued_document_1', $calls[0]['call_id']);
                self::assertSame($calls[0]['call_id'], $outputs[0]['call_id']);
                self::assertSame($this->args('арматура'), json_decode($calls[0]['arguments'], true, 512, JSON_THROW_ON_ERROR));
                $expected = $manifest['sources'][0]['projection'];
                $expected['data']['fragments'] = [$expected['data']['fragments'][1]];
                $expected['source_refs'] = [$expected['source_refs'][1]];
                self::assertSame($expected, json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR));
                self::assertStringNotContainsString('НЕУТВЕРЖДЕННЫЙ', $outputs[0]['output']);
                return $this->sdkResponse('resp_queued_document_2', [['type' => 'message', 'id' => 'msg_queued_document_2',
                    'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => self::ANSWER, 'annotations' => []]]]]);
            },
        ]);
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new OrdinaryAssistantEgress($context);
        $logging = app(LoggingService::class);
        $provider = (new OrdinaryAssistantProviderFactory($egress, $logging))->make($mock);
        $service = app()->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context]);
        $this->app->instance(AIAssistantService::class, $service);
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        self::assertSame('running', $started['request']->status);
        self::assertSame('queued', $started['request']->stage);
        self::assertNull($started['request']->started_at);
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        $escaped = null;
        $jobStarted = hrtime(true);
        try {
            (new ExecuteAssistantChatJob($started['request']->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class));
        } catch (Throwable $failure) {
            $observed = $probe->failure;
            if ($observed !== null) {
                $allowed = ['assistant_authorization_batch_during_query_compilation', 'assistant_source_aggregate_during_query_compilation',
                    'assistant_branch_aggregate_requires_source_projection', 'assistant_prepared_candidates_require_query_compilation',
                    'assistant_document_retrieval_aggregate_missing', 'temporary_database_connection_already_registered'];
                fwrite(STDERR, json_encode(['queued_document_original_class' => $observed::class,
                    'queued_document_invariant' => in_array($observed->getMessage(), $allowed, true) ? $observed->getMessage() : null,
                    'queued_document_origin_file' => basename($observed->getFile()), 'queued_document_origin_line' => $observed->getLine()], JSON_THROW_ON_ERROR).PHP_EOL);
            }
            if ($revocation === null && $beforeWireRevocation === null) { throw $failure; }
            self::assertInstanceOf($beforeWireRevocation !== null && $beforeWireRevocation !== 'actor'
                ? AssistantEgressDenied::class : AuthorizationException::class, $failure);
            $escaped = $failure;
        }
        $this->jobDurationMs = (hrtime(true) - $jobStarted) / 1_000_000;
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        if ($beforeWireRevocation !== null) {
            self::assertTrue($beforeWireFired);
            self::assertNotNull($escaped);
            self::assertCount(0, $wire);
            self::assertSame(0, $probe->executions);
            $request = AssistantRequest::query()->findOrFail($started['request']->id);
            self::assertSame('failed', $request->status);
            self::assertSame($beforeWireRevocation === 'actor' ? 'access_revoked' : 'assistant_egress_denied', $request->error_code);
            self::assertSame(1, $request->calls_used);
            self::assertSame(0, Conversation::query()->findOrFail($request->conversation_id)->messages()->where('role', 'assistant')->count());
            $usage = \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get();
            self::assertCount($beforeWireRevocation === 'actor' ? 1 : 0, $usage);
            if ($beforeWireRevocation === 'actor') {
                self::assertFalse($usage->first()->is_successful);
                self::assertFalse($usage->first()->metadata['provider_usage_available']);
            }
            return;
        }
        if ($revocation !== null) {
            self::assertNotNull($escaped);
            self::assertCount(1, $wire);
            self::assertSame(1, $probe->executions);
            self::assertNull($probe->failure);
            $request = AssistantRequest::query()->findOrFail($started['request']->id);
            self::assertSame('failed', $request->status);
            self::assertSame('access_revoked', $request->error_code);
            self::assertSame(1, $request->calls_used);
            self::assertSame(0, Conversation::query()->findOrFail($request->conversation_id)->messages()->where('role', 'assistant')->count());
            $usage = \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get();
            self::assertCount(1, $usage);
            self::assertTrue($usage->first()->is_successful);
            self::assertSame(100, $usage->first()->metadata['input_tokens']);
            return;
        }
        self::assertCount(2, $wire);
        self::assertSame(1, $probe->executions);
        self::assertNull($probe->failure);
        $request = AssistantRequest::query()->findOrFail($started['request']->id);
        self::assertSame('completed', $request->status);
        self::assertSame(2, $request->calls_used);
        self::assertSame(3, $request->max_calls);
        self::assertSame(self::ANSWER, $request->response['message']['content']);
        self::assertStringNotContainsString(self::ACCOUNT, json_encode($request->response['message']['metadata'], JSON_THROW_ON_ERROR));
        self::assertCount(1, $request->response['message']['metadata']['source_refs']);
        self::assertSame(OrdinaryAssistantDocumentProjection::VERSION, $request->response['message']['metadata']['source_refs'][0]['egress_projection_version']);
        $usage = \App\Models\Credits\AICreditProviderUsage::query()->where('ai_credit_reservation_id', $request->reservation_id)->get();
        self::assertCount(2, $usage);
        self::assertTrue($usage->every(static fn ($row): bool => (bool) $row->is_successful));
    }

    private function sdkResponse(string $id, array $output): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id, 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed',
            'model' => 'openai/gpt-6-luna', 'incomplete_details' => null, 'output' => $output,
            'max_output_tokens' => 1024, 'parallel_tool_calls' => false, 'previous_response_id' => null,
            'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], JSON_THROW_ON_ERROR));
    }

    private function args(string $query): array
    {
        return ['query' => $query, 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
    }

    private function enroll(): array
    {
        $cohort = $this->enrollments->assign($this->operator, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, [OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantDocumentProjection::VERSION);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Найди в утверждённом тексте ответ и при необходимости уточни поиск.',
            'conversation_id' => null, 'profile' => 'short', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => ['project_id' => $this->project->id,
                'document_id' => $this->document->id, 'chunk_ids' => array_column(array_slice($this->chunks, 0, 3), 'id')]]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

}
