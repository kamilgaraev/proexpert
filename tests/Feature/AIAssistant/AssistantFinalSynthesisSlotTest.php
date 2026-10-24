<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;


final class AssistantFinalSynthesisSlotTest extends TestCase
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
    private const ANSWER = 'В проекте одно критическое замечание; в утверждённом тексте указано 17 тонн арматуры.';

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
        config(['ai-assistant-credits.profiles.normal.max_calls' => 3, 'cache.default' => 'array', 'logging.default' => 'stderr', 'ai-assistant.enabled' => true,
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
    public function test_actual_queued_mixed_tools_reserve_the_last_native_call_for_synthesis(): void
    {
        $this->runQueued('positive');
    }

    public static function earlierAnswers(): array
    {
        return ['zero_reads' => [0], 'document_first_then_attention' => [2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('earlierAnswers')]
    public function test_model_can_finish_early_or_choose_document_before_attention(int $reads): void
    {
        $this->runQueued('reverse', 3, $reads);
    }

    public static function malformedFinalCalls(): array
    {
        return ['accepted_cap3' => [3], 'lowered_cap1' => [1]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedFinalCalls')]
    public function test_function_call_despite_final_none_never_executes_another_tool(int $cap): void
    {
        $this->runQueued('malicious', $cap, $cap - 1);
    }

    public static function lateRevocations(): array
    {
        return ['unused_sql' => ['unused_sql'], 'reviewer' => ['reviewer']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lateRevocations')]
    public function test_final_none_keeps_all_source_and_reviewer_checks_before_wire(string $mutation): void
    {
        $this->runQueued($mutation);
    }

    private function runQueued(string $scenario, int $cap = 3, int $reads = 2): void
    {
        $this->prepareMixed();
        [$cohort, $payload, $manifest] = $this->enroll();
        self::assertCount(2, $manifest['sources']);
        $projected = array_column($manifest['sources'], 'projection', 'tool');
        self::assertCount(1, $projected[OrdinaryAssistantDocumentProjection::TOOL]['data']['fragments']);
        self::assertGreaterThan(340, mb_strpos($projected[OrdinaryAssistantDocumentProjection::TOOL]['data']['fragments'][0]['content'], '17 тонн'));
        $tools = $scenario === 'unused_sql' ? [OrdinaryAssistantDocumentProjection::TOOL, OrdinaryAssistantDocumentProjection::TOOL]
            : ($scenario === 'reverse' ? [OrdinaryAssistantDocumentProjection::TOOL, 'get_project_attention']
                : ['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL]);
        $tools = array_slice($tools, 0, $reads);
        $wire = [];
        $responses = [];
        $context = app(OrdinaryAssistantScopedContext::class);
        $egress = new OrdinaryAssistantEgress($context);
        $permitChecks = 0;
        $toolPhases = 0;
        $referencePhases = [];
        $reservations = 0;
        $prepared = 0;
        $mutated = false;
        $observing = true;
        Log::listen(function (MessageLogged $event) use ($context, $egress, $payload, $scenario, $cap,
            &$toolPhases, &$referencePhases, &$reservations, &$prepared, &$mutated, &$permitChecks, &$observing): void {
            if (! $observing || $event->message !== 'ai.assistant.request_phase_completed'
                || ($event->context['request_id'] ?? null) !== $payload['request_id']) { return; }
            if (($event->context['phase'] ?? null) === 'tool') { $toolPhases++; }
            if (in_array($event->context['phase'] ?? null, ['complete_reference_first', 'complete_reference_second'], true)) {
                $referencePhases[] = [$event->context['phase'], $event->context['success']];
            }
            if (($event->context['phase'] ?? null) === 'provider_reservation' && ($event->context['success'] ?? false)) {
                $reservations++;
                if ($scenario === 'positive' && $reservations === $cap) {
                    $request = $this->armedRequest($egress);
                    $registry = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantCurrentRequest::class);
                    $foreign = new AssistantRequest(['request_id' => (string) Str::uuid(),
                        'user_id' => $this->fixture->owner->id, 'organization_id' => $this->fixture->organization->id]);
                    $previous = $registry->replace($foreign);
                    try { self::assertFalse($context->authorize($request)->isReady()); }
                    finally { $registry->replace($previous); }
                    $permitChecks++;
                    $changed = $request->payload();
                    foreach ($changed['input'] as &$item) {
                        if (($item['type'] ?? null) === 'function_call_output') {
                            $output = json_decode($item['output'], true, 512, JSON_THROW_ON_ERROR);
                            $output['data']['unapproved'] = self::ACCOUNT;
                            $item['output'] = json_encode($output, JSON_THROW_ON_ERROR);
                            break;
                        }
                    }
                    unset($item);
                    self::assertFalse($context->authorize($this->alteredRequest($request, $changed))->isReady());
                    $permitChecks++;
                }
            }
            if (($event->context['phase'] ?? null) === 'egress_prepare' && ($event->context['success'] ?? false)) {
                $prepared++;
                if ($prepared === $cap && in_array($scenario, ['unused_sql', 'reviewer'], true)) {
                    if ($scenario === 'unused_sql') {
                        QualityDefect::query()->where('project_id', $this->project->id)->update(['title' => 'Changed after final preparation']);
                    } else {
                        $this->reviewer->updateQuietly(['is_active' => false]);
                    }
                    $mutated = true;
                }
            }
        });
        foreach (range(1, $reads + 1) as $ordinal) {
            $responses[] = function (RequestInterface $request) use (&$wire, &$permitChecks, $context, $egress, $ordinal, $reads, $scenario, $tools, $payload): Response {
                self::assertTrue(app()->bound(AssistantRequestExecutionContext::class));
                $raw = (string) $request->getBody();
                self::assertStringNotContainsString(self::ACCOUNT, $raw);
                $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $wire[] = $body;
                if ($scenario === 'positive') {
                    $admitted = $this->armedRequest($egress);
                    $changed = $admitted->payload();
                    $changed['tool_choice'] = $ordinal === 3 ? 'auto' : 'none';
                    self::assertFalse($context->authorize($this->alteredRequest($admitted, $changed))->isReady());
                    $permitChecks++;
                    if ($ordinal === 3) {
                        self::assertFalse($context->authorize($admitted)->isReady());
                        $permitChecks++;
                    }
                    if ($ordinal === 1 || $ordinal === 3) {
                        $current = AssistantRequest::query()->where('request_id', $payload['request_id'])->firstOrFail();
                        try {
                            $context->beginFinalSynthesis($current, $body['input'], $body['tools']);
                            self::fail('Wrong-phase or repeated final permit accepted');
                        } catch (\App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied) {
                            $permitChecks++;
                        }
                    }
                }
                if ($ordinal > $reads && $scenario !== 'malicious') {
                    $answer = $reads === 0 ? 'Недостаточно сведений для ответа.' : self::ANSWER;
                    return $this->sdkResponse('resp_final_'.$ordinal, [['type' => 'message', 'id' => 'msg_final_'.$ordinal, 'status' => 'completed',
                        'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $answer, 'annotations' => []]]]]);
                }
                $tool = $tools[$ordinal - 1] ?? OrdinaryAssistantDocumentProjection::TOOL;
                $arguments = $tool === 'get_project_attention' ? ['project_id' => $this->project->id, 'focus' => 'quality', 'limit' => 3] : $this->args('арматура');
                return $this->sdkResponse('resp_final_'.$ordinal, [['type' => 'function_call', 'id' => 'fc_final_'.$ordinal,
                    'call_id' => 'call_final_'.$ordinal, 'status' => 'completed', 'name' => $tool,
                    'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]]);
            };
        }
        $provider = (new OrdinaryAssistantProviderFactory($egress, app(LoggingService::class)))->make(new MockHandler($responses));
        $this->app->instance(AIAssistantService::class, app()->makeWith(AIAssistantService::class,
            ['llmProvider' => $provider, 'ordinaryEgress' => $egress, 'scopedContext' => $context]));
        $started = app(QueuedAssistantChatService::class)->submit($cohort->organization_id, $this->fixture->owner, null, $payload, 'admin');
        if ($cap === 1) {
            $started['request']->updateQuietly(['max_calls' => 1]);
        }
        $failure = null;
        try {
            (new ExecuteAssistantChatJob($started['request']->id))->handle(app(AssistantRequestLifecycle::class), app(AssistantDataAccessPolicy::class));
        } catch (\App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied $denied) {
            $failure = $denied;
        } finally {
            $observing = false;
        }
        self::assertFalse(app()->bound(AssistantRequestExecutionContext::class));
        self::assertNull((new \ReflectionProperty($context, 'finalSynthesis'))->getValue($context));
        $revoked = in_array($scenario, ['unused_sql', 'reviewer'], true);
        self::assertSame($revoked, $mutated);
        self::assertCount($revoked ? $reads : $reads + 1, $wire);
        self::assertSame($reads, $toolPhases);
        foreach ($wire as $index => $body) {
            self::assertEqualsCanonicalizing(['get_project_attention', OrdinaryAssistantDocumentProjection::TOOL], array_column($body['tools'], 'name'));
            self::assertSame($index === $cap - 1 ? 'none' : 'auto', $body['tool_choice']);
            $outputs = array_values(array_filter($body['input'], static fn (array $item): bool => ($item['type'] ?? '') === 'function_call_output'));
            self::assertCount($index, $outputs);
            foreach ($outputs as $outputIndex => $output) {
                self::assertSame('call_final_'.($outputIndex + 1), $output['call_id']);
                self::assertSame($projected[$tools[$outputIndex]], json_decode($output['output'], true, 512, JSON_THROW_ON_ERROR));
            }
        }
        $current = AssistantRequest::query()->findOrFail($started['request']->id);
        self::assertSame($cap, $current->max_calls);
        self::assertSame($reads + 1, $current->calls_used);
        $usage = AICreditProviderUsage::query()->where('ai_credit_reservation_id', $current->reservation_id)->orderBy('id')->get();
        self::assertCount(count($wire), $usage);
        self::assertSame(range(1, count($wire)), $usage->map(static fn ($row): mixed => $row->metadata['attempt'] ?? null)->all());
        self::assertSame(count($wire), $usage->pluck('usage_key')->unique()->count());
        self::assertTrue($usage->every(static fn ($row): bool => ($row->metadata['provider_usage_available'] ?? null) === true
            && ($row->metadata['input_tokens'] ?? null) === 100 && ($row->metadata['output_tokens'] ?? null) === 20));
        self::assertTrue($usage->every(static fn ($row): bool => $row->cost_micro_rub > 0));
        if ($scenario === 'malicious') {
            self::assertSame(array_merge(array_fill(0, $reads, true), [false]), $usage->pluck('is_successful')->all());
        }
        $mirrors = DB::table('ai_usage_records')->where('organization_id', $current->organization_id)->where('user_id', $current->user_id)
            ->where('operation', 'assistant_chat')->where('metadata->request_id', $current->request_id)->orderBy('id')
            ->get(['input_tokens', 'output_tokens', 'metadata']);
        self::assertCount(count($wire), $mirrors);
        $mirrorKeys = [];
        foreach ($mirrors as $index => $mirror) {
            $metadata = json_decode($mirror->metadata, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($metadata['provider_usage_available']);
            self::assertTrue($metadata['cost_available']);
            self::assertSame($index + 1, $metadata['attempt']);
            self::assertSame($usage[$index]->is_successful, $metadata['is_successful']);
            self::assertSame(100, (int) $mirror->input_tokens);
            self::assertSame(20, (int) $mirror->output_tokens);
            $mirrorKeys[] = $metadata['usage_key'];
        }
        self::assertCount(count($wire), array_unique($mirrorKeys));
        if ($failure === null) {
            self::assertSame('completed', $current->status);
            self::assertTrue($usage->every(static fn ($row): bool => (bool) $row->is_successful));
            self::assertCount($reads, $current->response['message']['metadata']['source_refs']);
            self::assertSame([['complete_reference_first', true], ['complete_reference_second', true]], $referencePhases);
            if ($reads > 0) { self::assertSame(self::ANSWER, $current->response['message']['content']); }
        } else {
            self::assertSame([], $referencePhases);
            self::assertContains($scenario, ['malicious', 'unused_sql', 'reviewer']);
            self::assertSame('failed', $current->status);
            self::assertSame(0, \App\BusinessModules\Features\AIAssistant\Models\Message::query()
                ->where('conversation_id', $current->conversation_id)->where('role', 'assistant')->count());
            $reservation = \App\Models\Credits\AICreditReservation::query()->findOrFail($current->reservation_id);
            self::assertSame('cancelled', $reservation->status);
            self::assertSame(0, $reservation->consumed_minor);
        }
        self::assertSame($scenario === 'malicious' || $revoked, $failure !== null);
        if ($scenario === 'positive') { self::assertSame(8, $permitChecks); }
    }

    private function armedRequest(OrdinaryAssistantEgress $egress): \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest
    {
        $request = (new \ReflectionProperty($egress->transport(), 'request'))->getValue($egress->transport());
        self::assertInstanceOf(\App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest::class, $request);
        return $request;
    }

    private function alteredRequest(\App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest $original,
        array $payload): \App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest
    {
        $copy = (new \ReflectionClass($original))->newInstanceWithoutConstructor();
        foreach (['scope' => $original->scope(), 'payload' => $payload, 'digest' => $original->binding()] as $field => $value) {
            (new \ReflectionProperty($copy, $field))->setValue($copy, $value);
        }
        return $copy;
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
