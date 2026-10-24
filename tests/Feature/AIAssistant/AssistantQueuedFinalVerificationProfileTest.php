<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDocumentsTool;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantQueuedFinalVerificationProfileTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private User $operator;
    private AIAssistantDocument $document;
    private array $chunks = [];
    private OrdinaryAssistantEnrollmentService $enrollments;
    private mixed $originalRedis = null;
    private string $output;
    private const SENTINEL = 'APPROVED_FRAGMENT_SENTINEL';
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';
    private const ANSWER = 'В утверждённом фрагменте указано 17 тонн арматуры.';

    protected function setUp(): void
    {
        parent::setUp();
        $output = getenv('MOST_ASSISTANT_FINAL_PROFILE_OUTPUT');
        if (! is_string($output) || $output === '') {
            self::markTestSkipped('Explicit external profiling artifact path is required.');
        }
        $directory = realpath(dirname($output));
        $checkout = realpath(base_path());
        self::assertIsString($directory);
        self::assertIsString($checkout);
        $directory = strtolower(str_replace('\\', '/', $directory));
        $checkout = strtolower(str_replace('\\', '/', $checkout));
        self::assertFalse(str_starts_with($directory.'/', $checkout.'/'));
        self::assertSame('json', pathinfo($output, PATHINFO_EXTENSION));
        self::assertFileDoesNotExist($output);
        $this->output = $output;
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->originalRedis = Redis::getFacadeRoot();
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
        foreach (['арматура: '.str_repeat('учебные сведения ', 32).'Утверждённый объём: 17 тонн '.self::SENTINEL] as $number => $text) {
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->originalRedis !== null) { Redis::swap($this->originalRedis); }
        parent::tearDown();
    }

    public function test_profiles_two_admitted_reads_and_final_verification_in_the_actual_job(): void
    {
        [$cohort, $payload, $manifest] = $this->enroll();
        $expected = $manifest['sources'][0]['projection'];
        self::assertCount(1, $expected['data']['fragments']);
        $fragment = $expected['data']['fragments'][0]['content'];
        self::assertGreaterThan(340, mb_strpos($fragment, '17 тонн'));
        self::assertInstanceOf(SearchAssistantDocumentsTool::class, app(AIToolRegistry::class)->getTool(OrdinaryAssistantDocumentProjection::TOOL));
        $wire = [];
        $sdkMs = [];
        $contextPresent = [];
        $responses = [];
        foreach (['арматура', '17 тонн', null] as $index => $query) {
            $responses[] = function (RequestInterface $request) use ($index, $query, &$wire, &$sdkMs, &$contextPresent): Response {
                $started = hrtime(true);
                $wire[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $contextPresent[] = app()->bound(AssistantRequestExecutionContext::class);
                usleep(20_000);
                $response = $query === null
                    ? $this->sdkResponse('resp_final_profile_3', [['type' => 'message', 'id' => 'msg_final_profile_3',
                        'status' => 'completed', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => self::ANSWER, 'annotations' => []]]]])
                    : $this->sdkResponse('resp_final_profile_'.($index + 1), [['type' => 'function_call', 'id' => 'fc_final_profile_'.($index + 1),
                        'call_id' => 'call_final_profile_'.($index + 1), 'status' => 'completed', 'name' => OrdinaryAssistantDocumentProjection::TOOL,
                        'arguments' => json_encode($this->args($query), JSON_THROW_ON_ERROR)]]);
                $sdkMs[] = (hrtime(true) - $started) / 1_000_000;
                return $response;
            };
        }
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new OrdinaryAssistantEgress($context);
        $provider = (new OrdinaryAssistantProviderFactory($egress, app(LoggingService::class)))->make(new MockHandler($responses));
        $service = app()->makeWith(AIAssistantService::class, ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context]);
        $this->app->instance(AIAssistantService::class, $service);
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        self::assertSame('running', $started['request']->status);
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        $active = false;
        $queries = 0;
        $sqlMs = 0.0;
        $observerMs = 0.0;
        $buckets = array_fill_keys(['stage', 'generic', 'financial', 'structured', 'validate_results', 'publication', 'final_save', 'other'], ['queries' => 0, 'reported_sql_ms' => 0.0]);
        $phaseBuckets = array_fill_keys(['final_verification', 'outside_final', 'unresolved'], $buckets);
        $servicePath = str_replace('\\', '/', app_path('BusinessModules/Features/AIAssistant/Services/AIAssistantService.php'));
        $phases = [];
        DB::listen(static function (QueryExecuted $event) use (&$active, &$queries, &$sqlMs, &$observerMs, &$buckets, &$phaseBuckets, $servicePath): void {
            if (! $active) { return; }
            $observerStarted = hrtime(true);
            $category = 'other';
            $phase = 'unresolved';
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
            $rootSeen = false;
            $serviceSeen = false;
            $unrecognizedFinal = false;
            $knownFinal = false;
            foreach ($frames as $frame) {
                $class = $frame['class'] ?? '';
                $method = $frame['function'] ?? '';
                if ($class === ExecuteAssistantChatJob::class && $method === 'handle') { $rootSeen = true; }
                if (str_replace('\\', '/', $frame['file'] ?? '') !== $servicePath) { continue; }
                $serviceSeen = true;
                $line = $frame['line'] ?? 0;
                if ($line < 1046 || $line > 1440) { continue; }
                $recognized = ($class === AIAssistantService::class && in_array($method, ['stage', 'decorateMetadata'], true))
                    || ($class === OrdinaryAssistantScopedContext::class && $method === 'validateResults')
                    || ($class === AssistantResponseVerifier::class && $method === 'verify')
                    || ($class === AssistantFinancialClaimVerifier::class && $method === 'guard')
                    || ($class === AssistantStructuredFactVerifier::class && $method === 'guard');
                $knownFinal = $knownFinal || $recognized;
                $unrecognizedFinal = $unrecognizedFinal || ! $recognized;
            }
            if ($knownFinal && ! $unrecognizedFinal) { $phase = 'final_verification'; }
            elseif ($rootSeen && $serviceSeen && ! $unrecognizedFinal) { $phase = 'outside_final'; }
            foreach ($frames as $frame) {
                $class = $frame['class'] ?? '';
                $method = $frame['function'] ?? '';
                $candidate = match (true) {
                    $class === AssistantRequestLifecycle::class && $method === 'stage' => 'stage',
                    $class === AssistantResponseVerifier::class => 'generic',
                    $class === AssistantFinancialClaimVerifier::class => 'financial',
                    $class === AssistantStructuredFactVerifier::class => 'structured',
                    $class === OrdinaryAssistantScopedContext::class && $method === 'validateResults' => 'validate_results',
                    $class === OrdinaryAssistantScopedContext::class && $method === 'assertPublication' => 'publication',
                    $class === AssistantRequestLifecycle::class && $method === 'complete' => 'final_save',
                    $class === AIAssistantService::class && $method === 'savePendingSummary' => 'final_save',
                    default => null,
                };
                if ($candidate !== null) { $category = $candidate; break; }
            }
            $queries++;
            $sqlMs += $event->time;
            $buckets[$category]['queries']++;
            $buckets[$category]['reported_sql_ms'] += $event->time;
            $phaseBuckets[$phase][$category]['queries']++;
            $phaseBuckets[$phase][$category]['reported_sql_ms'] += $event->time;
            $observerMs += (hrtime(true) - $observerStarted) / 1_000_000;
        });
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$active, &$phases): void {
            if (! $active || $event->message !== 'ai.assistant.request_phase_completed' || count($phases) >= 64) { return; }
            $phase = $event->context['phase'] ?? null;
            if (! in_array($phase, ['job_startup', 'service_graph', 'request_permission', 'conversation', 'request_context', 'access_context',
                'task_plan', 'preparation', 'messages', 'catalog', 'tool_definitions', 'provider_prepare', 'tool', 'final_verification',
                'request_complete', 'summary_publish', 'read_permit_wait', 'read_permit_hold'], true)) { return; }
            $duration = $event->context['duration_ms'] ?? null;
            if (! is_numeric($duration) || ! is_finite((float) $duration) || (float) $duration < 0) { return; }
            $phases[] = ['phase' => $phase, 'duration_ms' => (float) $duration, 'success' => ($event->context['success'] ?? false) === true];
        });
        $jobStarted = hrtime(true);
        $active = true;
        try {
            (new ExecuteAssistantChatJob($started['request']->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class));
        } finally {
            $active = false;
            $jobMs = (hrtime(true) - $jobStarted) / 1_000_000;
            file_put_contents($this->output, json_encode(['scope' => 'synthetic_actual_job_normal_profile', 'nearest_known_frame_depth' => 96,
                'laravel_transaction_level' => DB::transactionLevel(), 'pdo_transaction' => DB::connection()->getPdo()->inTransaction(),
                'job_wall_ms' => $jobMs, 'queries' => $queries, 'reported_sql_ms' => $sqlMs, 'query_observer_wall_ms' => $observerMs,
                'sql_categories' => $buckets, 'phase_sql_categories' => $phaseBuckets, 'phases' => $phases, 'sdk_mock_ms' => $sdkMs], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        }
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        self::assertSame([true, true, true], $contextPresent);
        self::assertCount(3, $wire);
        foreach ($sdkMs as $duration) { self::assertGreaterThan(0, $duration); }
        foreach ($wire as $index => $body) {
            self::assertSame([OrdinaryAssistantDocumentProjection::TOOL], array_column($body['tools'], 'name'));
            self::assertStringNotContainsString(self::ACCOUNT, json_encode($body, JSON_THROW_ON_ERROR));
            $outputs = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            $calls = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call'));
            self::assertCount($index, $outputs);
            self::assertCount($index, $calls);
            foreach ($outputs as $number => $output) {
                self::assertSame('call_final_profile_'.($number + 1), $output['call_id']);
                self::assertSame($calls[$number]['call_id'], $output['call_id']);
                self::assertSame($expected, json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR));
                self::assertStringContainsString(self::SENTINEL, $output['output']);
            }
        }
        $request = AssistantRequest::query()->findOrFail($started['request']->id);
        self::assertSame('completed', $request->status);
        self::assertSame(3, $request->calls_used);
        self::assertGreaterThanOrEqual(3, $request->max_calls);
        self::assertSame(self::ANSWER, $request->response['message']['content']);
        self::assertCount(1, $request->response['message']['metadata']['source_refs']);
        self::assertSame(array_sum(array_column($buckets, 'queries')), $queries);
        self::assertEqualsWithDelta(array_sum(array_column($buckets, 'reported_sql_ms')), $sqlMs, 0.001);
        self::assertSame($queries, array_sum(array_map(static fn (array $categories): int => array_sum(array_column($categories, 'queries')), $phaseBuckets)));
        self::assertGreaterThan(0, array_sum(array_column($phaseBuckets['final_verification'], 'queries')));
        self::assertNotEmpty(array_filter($phases, static fn (array $phase): bool => $phase['phase'] === 'final_verification' && $phase['success']));
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
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->fixture->owner, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => ['project_id' => $this->project->id,
                'document_id' => $this->document->id, 'chunk_ids' => array_column($this->chunks, 'id')]]]);
        $this->enrollments->confirm($this->fixture->owner, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

}
