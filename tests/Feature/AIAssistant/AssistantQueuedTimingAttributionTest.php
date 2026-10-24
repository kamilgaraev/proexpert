<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantDocumentProjection;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEnrollmentService;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantSourceProjection;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Logging\LoggingService;
use App\Services\Modules\PackageCatalogService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Services\AssistantHttpRequestOptions;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestPhaseTimer;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantTransport;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;

final class AssistantQueuedTimingAttributionTest extends TestCase
{
    protected array $connectionsToTransact = [];

    private AssistantRealAuthorizationFixture $fixture;
    private Project $project;
    private AIAssistantDocument $document;
    private RagChunk $chunk;
    private User $reviewer;
    private OrdinaryAssistantEnrollmentService $enrollments;
    private mixed $originalRedis;
    private const ACCOUNT = 'PRIVATE_ACCOUNT_NOT_APPROVED';
    private const ANSWER = 'В утверждённом фрагменте указано 17 тонн арматуры.';

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        $this->originalRedis = Redis::getFacadeRoot();
        Redis::swap(new class
        {
            public function connection(?string $name = null): object
            {
                return new class
                {
                    public function eval(mixed ...$arguments): int { return 1; }
                };
            }
        });
        config(['cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true,
            'ai-assistant-credits.enforce' => true, 'ai-assistant.llm.provider' => 'timeweb',
            'ai-assistant.llm.timeweb.base_uri' => 'https://fixture.invalid/v1',
            'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key']);
    }

    private function prepareMixed(): void
    {
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $this->fixture->owner->updateQuietly(['name' => self::ACCOUNT]);
        $this->project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        QualityDefect::withoutEvents(fn () => QualityDefect::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id,
            'defect_number' => (string) Str::uuid(), 'title' => 'Учебное критическое замечание',
            'severity' => 'critical', 'status' => 'open', 'due_date' => '2026-10-12']));
        $this->reviewer = User::factory()->create(['current_organization_id' => $this->fixture->organization->id, 'is_active' => true]);
        $this->fixture->organization->users()->attach($this->reviewer->id,
            ['is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects']);
        UserRoleAssignment::query()->create(['user_id' => $this->reviewer->id,
            'role_slug' => 'organization_owner', 'role_type' => UserRoleAssignment::TYPE_SYSTEM,
            'context_id' => AuthorizationContext::getOrganizationContext($this->fixture->organization->id)->id,
            'assigned_by' => $this->fixture->owner->id, 'is_active' => true]);
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
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $this->project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $this->project->id, 'storage_path' => $file->path,
            'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', 'document'), 'size_bytes' => 100,
            'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(), 'extracted_text' => 'Учебный текст', 'metadata' => []]));
        $text = 'арматура: '.str_repeat('учебные сведения ', 32).'Утверждённый объём: 17 тонн';
        $unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id, 'unit_type' => 'page',
            'unit_index' => 0, 'text' => $text, 'checksum' => hash('sha256', $text), 'provenance' => ['page' => 1]]);
        $data = FileRagSource::chunkForUnit($this->document, $unit);
        $source = RagSource::query()->create((new RagIndexer($provider, new RagSourceRegistry([])))->coverageIdentity($data)
            + ['title' => $data->title, 'source_version' => now()->toAtomString(), 'metadata' => $data->metadata, 'indexed_at' => now()]);
        $this->chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId,
            'project_id' => $data->projectId, 'chunk_index' => 0, 'content' => $text, 'content_hash' => hash('sha256', $text),
            'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1], 'embedding_provider' => 'offline', 'embedding_model' => 'offline']);
        $this->enrollments = app(OrdinaryAssistantEnrollmentService::class);
        $this->actingAs($this->fixture->owner);
        app(AICreditService::class)->grant($this->fixture->organization, 100000, 'purchase', null, 'finalization-'.Str::uuid());
    }

    protected function tearDown(): void
    {
        Redis::swap($this->originalRedis);
        Carbon::setTestNow();
        parent::tearDown();
    }


    public function test_actual_queued_mixed_sources_attribute_handler_progress_staging_and_completion(): void
    {
        $this->runQueued(false);
    }

    public function test_actual_queued_completion_reference_failure_keeps_the_original_deadline_and_usage(): void
    {
        $this->runQueued(true);
    }

    public function test_transport_timing_waits_for_settlement_and_preserves_rejection_and_default_paths(): void
    {
        $events = [];
        $this->capture($events);
        $id = (string) Str::uuid();
        $pending = new Promise;
        [$client, $url, $body] = $this->transport(static fn () => $pending, $id);
        $promise = $client->requestAsync('POST', $url, ['headers' => ['Content-Type' => 'application/json'], 'body' => $body, 'http_errors' => false]);
        self::assertSame('pending', $promise->getState());
        self::assertCount(0, $this->phase($events, 'native_http_handler'));
        usleep(2_000);
        $response = new Response(400, [], 'PRIVATE_BODY');
        $pending->resolve($response);
        self::assertSame($response, $promise->wait());
        $handler = $this->phase($events, 'native_http_handler');
        self::assertCount(1, $handler);
        self::assertTrue($handler[0]['success']);
        self::assertGreaterThan(0, $handler[0]['duration_ms']);
        self::assertSame(0, $handler[0]['progress_guard_count']);
        self::assertSame(0.0, $handler[0]['progress_guard_ms']);
        foreach ([new RuntimeException('PRIVATE_EXCEPTION'), 'PRIVATE_REASON', ['PRIVATE_REASON']] as $reason) {
            $pending = new Promise;
            [$client, $url, $body] = $this->transport(static fn () => $pending, $id);
            $promise = $client->requestAsync('POST', $url, ['headers' => ['Content-Type' => 'application/json'], 'body' => $body]);
            $observed = null;
            $promise = $promise->then(null, static function (mixed $failure) use (&$observed) {
                $observed = $failure;
                return Create::rejectionFor($failure);
            });
            $pending->reject($reason);
            $promise->wait(false);
            try {
                $promise->wait();
                self::fail('Rejection was swallowed');
            } catch (\Throwable $actual) {
                if ($reason instanceof \Throwable) {
                    self::assertSame($reason, $actual);
                } else {
                    self::assertInstanceOf(\GuzzleHttp\Promise\RejectionException::class, $actual);
                    self::assertSame($reason, $actual->getReason());
                }
            }
            self::assertSame($reason, $observed);
            self::assertFalse(array_slice($this->phase($events, 'native_http_handler'), -1)[0]['success']);
        }
        $failure = new RuntimeException('PRIVATE_SYNC');
        [$client, $url, $body] = $this->transport(static function () use ($failure) { throw $failure; }, $id);
        try {
            $client->requestAsync('POST', $url, ['headers' => ['Content-Type' => 'application/json'], 'body' => $body])->wait();
            self::fail('Synchronous failure was swallowed');
        } catch (RuntimeException $actual) { self::assertSame($failure, $actual); }
        self::assertCount(5, $this->phase($events, 'native_http_handler'));
        $before = count($events);
        [$client, $url, $body] = $this->transport(static fn () => Create::promiseFor($response), null);
        self::assertSame($response, $client->request('POST', $url, ['headers' => ['Content-Type' => 'application/json'], 'body' => $body, 'http_errors' => false]));
        self::assertCount($before, $events);
        $calls = 0;
        $denial = new AssistantEgressDenied('source_stale');
        [$client, $url, $body] = $this->transport(static function () use (&$calls, $response) { $calls++; return Create::promiseFor($response); }, $id, static function () use ($denial) { throw $denial; });
        try { $client->requestAsync('POST', $url, ['headers' => ['Content-Type' => 'application/json'], 'body' => $body])->wait(); self::fail('Guard denial was swallowed'); }
        catch (AssistantEgressDenied $actual) { self::assertSame($denial, $actual); }
        self::assertSame(0, $calls);
        $logger = Log::getFacadeRoot();
        try {
            Log::swap(new class { public function info(mixed ...$args): void { throw new RuntimeException('PRIVATE_LOGGER'); } });
            [$client, $url, $body] = $this->transport(static fn () => Create::promiseFor($response), $id);
            self::assertSame($response, $client->request('POST', $url, ['headers' => ['Content-Type' => 'application/json'], 'body' => $body, 'http_errors' => false]));
        } finally { Log::swap($logger); }
        AssistantRequestPhaseTimer::start($id, 'native_http_handler')->finish(successful: true, progressGuardCount: 65537, progressGuardMilliseconds: 1);
        $last = array_slice($this->phase($events, 'native_http_handler'), -1)[0];
        self::assertNull($last['progress_guard_count']);
        self::assertNull($last['progress_guard_ms']);
        self::assertStringNotContainsString('PRIVATE_', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public function test_progress_observer_counts_only_actual_checks_and_never_changes_throttle_or_abort(): void
    {
        $checks = 0;
        $durations = [];
        $options = AssistantHttpRequestOptions::forCheckpoint(2, static function () use (&$checks): void { $checks++; usleep(1_000); },
            static function (float $duration) use (&$durations): void { $durations[] = $duration; });
        ($options['progress'])(0, 0, 0, 0);
        ($options['progress'])(0, 0, 0, 0);
        if (defined('CURLOPT_XFERINFOFUNCTION') && isset($options['curl'][CURLOPT_XFERINFOFUNCTION])) {
            self::assertSame(0, ($options['curl'][CURLOPT_XFERINFOFUNCTION])(null, 0, 0, 0, 0));
        }
        self::assertSame(1, $checks);
        self::assertCount(1, $durations);
        self::assertGreaterThan(0, $durations[0]);
        $failure = new AssistantRequestDeadlineExceeded;
        $checks = 0;
        $observed = 0;
        $options = AssistantHttpRequestOptions::forCheckpoint(2, static function () use (&$checks, $failure): void { $checks++; throw $failure; },
            static function () use (&$observed): void { $observed++; throw new RuntimeException('PRIVATE_OBSERVER'); });
        foreach ([1, 2] as $_) {
            try { ($options['progress'])(0, 0, 0, 0); self::fail('Abort lost'); }
            catch (AssistantRequestDeadlineExceeded $actual) { self::assertSame($failure, $actual); }
        }
        self::assertSame(1, $checks);
        self::assertSame(1, $observed);
        $plain = AssistantHttpRequestOptions::forCheckpoint(2, static function (): void {});
        self::assertSame(array_keys($plain), array_keys($options));
        ($plain['progress'])(0, 0, 0, 0);
    }

    private function runQueued(bool $deny): void
    {
        $this->prepareMixed();
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertCount(2, $manifest['sources']);
        $expected = array_column($manifest['sources'], 'projection', 'tool')[OrdinaryAssistantDocumentProjection::TOOL];
        self::assertGreaterThan(340, mb_strpos($expected['data']['fragments'][0]['content'], '17 тонн'));
        $wire = [];
        $responses = [];
        foreach ([false, true] as $final) {
            $responses[] = function (RequestInterface $request, array $options) use (&$wire, $final): Response {
                self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                self::assertStringNotContainsString(self::ACCOUNT, (string) $request->getBody());
                $wire[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                ($options['progress'])(0, 0, 0, 0);
                usleep(20_000);
                return $final ? $this->sdkResponse('resp_timing_2', [['type' => 'message', 'id' => 'msg_timing_2', 'status' => 'completed',
                    'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => self::ANSWER, 'annotations' => []]]]])
                    : $this->sdkResponse('resp_timing_1', [['type' => 'function_call', 'id' => 'fc_timing_1', 'call_id' => 'call_timing_1',
                        'status' => 'completed', 'name' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => json_encode($this->args('арматура'), JSON_THROW_ON_ERROR)]]);
            };
        }
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new OrdinaryAssistantEgress($context);
        $provider = (new OrdinaryAssistantProviderFactory($egress, app(LoggingService::class)))->make(new MockHandler($responses));
        $this->app->instance(AIAssistantService::class, app()->makeWith(AIAssistantService::class,
            ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context]));
        $events = [];
        $this->capture($events);
        $armed = false;
        $injected = false;
        Log::listen(static function (MessageLogged $event) use (&$armed, $deny): void {
            if ($deny && ($event->context['phase'] ?? null) === 'complete_authorization' && ($event->context['success'] ?? false)) { $armed = true; }
        });
        $failure = new AssistantRequestDeadlineExceeded;
        $active = true;
        DB::connection()->beforeExecuting(static function () use (&$armed, &$injected, &$active, $failure): void {
            if (! $active || ! $armed || $injected) { return; }
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 48) as $frame) {
                if (($frame['class'] ?? null) === AssistantRequestLifecycle::class && ($frame['function'] ?? null) === 'assertResponseReferences') {
                    $injected = true;
                    throw $failure;
                }
            }
        });
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        try { (new ExecuteAssistantChatJob($started['request']->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class)); }
        finally { $active = false; }
        self::assertSame($deny, $injected);
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        self::assertCount(2, $wire);
        foreach ($wire as $index => $body) {
            self::assertSame('auto', $body['tool_choice']);
            self::assertEqualsCanonicalizing(['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], array_column($body['tools'], 'name'));
            $outputs = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($index, $outputs);
            if ($index === 1) { self::assertSame('call_timing_1', $outputs[0]['call_id']); self::assertSame($expected, json_decode($outputs[0]['output'], true, 512, JSON_THROW_ON_ERROR)); }
        }
        $current = AssistantRequest::query()->findOrFail($started['request']->id);
        self::assertSame(2, $current->calls_used);
        self::assertSame((int) config('ai-assistant-credits.profiles.normal.max_calls'), $current->max_calls);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $current->reservation_id)->get();
        self::assertCount(2, $usage);
        self::assertTrue($usage->every(static fn ($row): bool => (bool) $row->is_successful));
        $handlers = $this->phase($events, 'native_http_handler');
        self::assertCount(2, $handlers);
        foreach ($handlers as $handler) {
            self::assertTrue($handler['success']);
            self::assertSame(1, $handler['progress_guard_count']);
            self::assertGreaterThan(0, $handler['progress_guard_ms']);
            self::assertGreaterThanOrEqual($handler['progress_guard_ms'], $handler['duration_ms']);
        }
        foreach (['egress_prepare', 'provider_reservation', 'provider_post_sdk', 'native_prewire_guard', 'native_transport_options'] as $phase) {
            self::assertCount(2, $this->phase($events, $phase));
        }
        foreach (['response_staging', 'complete_lock', 'complete_authorization', 'complete_reference_first'] as $phase) { self::assertCount(1, $this->phase($events, $phase)); }
        foreach ($events as $event) {
            self::assertSame($payload['request_id'], $event['request_id']);
            self::assertSame([], array_values(array_diff(array_keys($event), ['request_id', 'phase', 'duration_ms', 'success', 'exception_class', 'progress_guard_count', 'progress_guard_ms'])));
            self::assertGreaterThanOrEqual(0, $event['duration_ms']);
        }
        if ($deny) {
            self::assertSame('failed', $current->status);
            self::assertSame('request_deadline_exceeded', $current->error_code);
            self::assertFalse($this->phase($events, 'complete_reference_first')[0]['success']);
            self::assertSame(AssistantRequestDeadlineExceeded::class, $this->phase($events, 'complete_reference_first')[0]['exception_class']);
            self::assertCount(0, $this->phase($events, 'complete_reference_second'));
            self::assertCount(0, $this->phase($events, 'complete_charge'));
            self::assertSame(0, Conversation::query()->findOrFail($current->conversation_id)->messages()->where('role', 'assistant')->count());
        } else {
            self::assertSame('completed', $current->status);
            self::assertSame(self::ANSWER, $current->response['message']['content']);
            self::assertCount(1, $current->response['message']['metadata']['source_refs']);
            foreach (['complete_reference_second', 'complete_actor_recheck', 'complete_charge', 'complete_persist', 'complete_publication'] as $phase) {
                self::assertCount(1, $this->phase($events, $phase)); self::assertTrue($this->phase($events, $phase)[0]['success']);
            }
        }
        self::assertStringNotContainsString(self::ACCOUNT, json_encode($events, JSON_THROW_ON_ERROR));
    }

    private function capture(array &$events): void
    {
        Log::listen(static function (MessageLogged $event) use (&$events): void {
            if ($event->message === 'ai.assistant.request_phase_completed' && (str_starts_with($event->context['phase'] ?? '', 'native_')
                || str_starts_with($event->context['phase'] ?? '', 'complete_')
                || in_array($event->context['phase'] ?? '', ['egress_prepare', 'provider_reservation', 'provider_post_sdk', 'provider_failure_cleanup', 'response_staging'], true))) { $events[] = $event->context; }
        });
    }

    private function phase(array $events, string $phase): array
    {
        return array_values(array_filter($events, static fn (array $event): bool => $event['phase'] === $phase));
    }

    private function transport(callable $handler, ?string $id, ?\Closure $guard = null): array
    {
        $url = 'https://fixture.invalid/v1/responses';
        $payload = ['model' => 'openai/gpt-6-luna', 'input' => []];
        $request = (new ReflectionClass(OrdinaryAssistantEgressRequest::class))->newInstanceWithoutConstructor();
        foreach (['scope' => ['endpoint' => $url], 'payload' => $payload, 'digest' => 'offline'] as $name => $value) {
            (new \ReflectionProperty($request, $name))->setValue($request, $value);
        }
        $transport = new OrdinaryAssistantTransport;
        $client = $transport->client($handler);
        $provider = $this->createStub(LLMProviderInterface::class);
        $transport->bind($provider);
        $transport->arm($provider, $request, $guard ?? static function (): void {}, 2, $id);
        return [$client, $url, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    private function enroll(): array
    {
        $cohort = $this->enrollments->assign($this->fixture->owner, $this->fixture->organization->id, $this->fixture->owner,
            $this->project->id, ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], OrdinaryAssistantSourceProjection::MIXED_VERSION);
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Найди в утверждённом тексте ответ и при необходимости уточни поиск.',
            'conversation_id' => null, 'profile' => 'normal', 'allow_actions' => false];
        $payload['quote_id'] = app(AICreditService::class)->quote($this->fixture->organization, $this->fixture->owner, $payload)['quote_id'];
        $manifest = $this->enrollments->preview($this->reviewer, $cohort->organization_id, $cohort->id, $payload['quote_id'], $payload,
            [['tool' => 'get_project_attention', 'arguments' => ['project_id' => $this->project->id, 'focus' => 'quality', 'limit' => 3]],
                ['tool' => OrdinaryAssistantDocumentProjection::TOOL, 'arguments' => ['project_id' => $this->project->id,
                    'document_id' => $this->document->id, 'chunk_ids' => [$this->chunk->id]]]]);
        $this->enrollments->confirm($this->reviewer, $cohort->organization_id, $manifest['enrollment_id'], $manifest['manifest_hash'], OrdinaryAssistantEnrollmentService::DECLARATION);
        return [$cohort, $payload, $manifest];
    }

    private function args(string $query): array
    {
        return ['query' => $query, 'project_id' => $this->project->id, 'source_types' => ['file_document'], 'limit' => 1, 'retrieval_mode' => 'lexical'];
    }

    private function sdkResponse(string $id, array $output): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => $id, 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed',
            'model' => 'openai/gpt-6-luna', 'incomplete_details' => null, 'output' => $output,
            'max_output_tokens' => 2048, 'parallel_tool_calls' => false, 'previous_response_id' => null,
            'temperature' => null, 'top_p' => null, 'tool_choice' => 'auto', 'tools' => [],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
        ], JSON_THROW_ON_ERROR));
    }
}
