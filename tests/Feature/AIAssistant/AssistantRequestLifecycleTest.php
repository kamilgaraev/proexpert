<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use PHPUnit\Framework\Attributes\DataProvider;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Events\AssistantRequestChanged;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestInProgress;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Models\ChatAttachment;
use App\BusinessModules\Features\AIAssistant\Services\AssistantChatAttachmentService;
use App\Services\Storage\FileService;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditQuote;
use App\Models\Credits\AICreditReservation;
use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use DomainException;
use RuntimeException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;
use Throwable;

final class AssistantRequestLifecycleTest extends TestCase
{
    private AICreditService $credits;
    private AssistantRequestLifecycle $lifecycle;
    private ConversationManager $conversations;
    private Organization $organization;
    private User $actor;
    private bool $assistantEnabled = true;
    private ?string $connectionName = null;
    private ?array $originalConnectionConfiguration = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config('database.connections.'.$this->connectionName);
        config()->set('database.connections.'.$this->connectionName, IsolatedPostgresTestDatabase::configuration());
        DB::purge($this->connectionName);
        DB::connection($this->connectionName);
        foreach ($this->tables() as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->boolean('is_active')->default(true); $table->boolean('is_verified')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password'); $table->boolean('is_active')->default(true); $table->unsignedBigInteger('current_organization_id')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id(); $table->foreignId('organization_id'); $table->foreignId('user_id'); $table->boolean('is_active')->default(true); $table->boolean('is_owner')->default(false); $table->timestamps();
        });
        Schema::create('commercial_orders', function (Blueprint $table): void { $table->id(); $table->string('kind')->default('purchase'); });
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id(); $table->foreignId('organization_id')->constrained(); $table->foreignId('user_id')->constrained(); $table->string('title')->nullable(); $table->jsonb('context')->nullable(); $table->timestamp('last_activity_at')->nullable(); $table->unsignedInteger('context_version')->default(1); $table->timestamps();
        });
        Schema::create('ai_conversation_participants', function (Blueprint $table): void {
            $table->id(); $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete(); $table->foreignId('user_id')->constrained(); $table->foreignId('added_by_user_id')->constrained('users'); $table->string('role'); $table->timestamps(); $table->unique(['conversation_id', 'user_id']);
        });
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id(); $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete(); $table->string('role'); $table->text('content'); $table->unsignedInteger('tokens_used')->default(0); $table->string('model')->nullable(); $table->jsonb('metadata')->nullable(); $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('ai_conversation_summaries', function (Blueprint $table): void {
            $table->id(); $table->foreignId('conversation_id')->unique()->constrained('ai_conversations')->cascadeOnDelete(); $table->text('summary')->nullable(); $table->jsonb('summary_segments')->nullable(); $table->jsonb('selected_entities')->nullable(); $table->jsonb('user_decisions')->nullable(); $table->jsonb('source_refs')->nullable(); $table->unsignedInteger('context_version')->default(1); $table->timestamps();
        });
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        (require database_path('migrations/2026_09_29_000009_create_ai_assistant_requests_table.php'))->up();
        (require database_path('migrations/2026_09_29_000010_add_async_payload_to_ai_assistant_requests.php'))->up();
        (require database_path('migrations/2026_09_30_000001_create_ai_chat_attachments_table.php'))->up();
        $this->organization = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Запросы помощника']));
        $this->actor = $this->member('Автор');
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = $this->mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'ai-assistant'])]));
        $policy = new AssistantDataAccessPolicy($authorization, $this->mock(UserProjectAccessService::class), $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $permissions = $this->mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->andReturnUsing(fn (User $user, int $organizationId): bool => $this->assistantEnabled && $policy->belongsToOrganization($user, $organizationId));
        $this->conversations = new ConversationManager($policy);
        $this->app->instance(ConversationManager::class, $this->conversations);
        $this->credits = new AICreditService;
        $this->lifecycle = new AssistantRequestLifecycle($this->credits, $permissions, $this->conversations, $policy);
        config()->set('ai-assistant-credits.enforce', true);
        $this->credits->grant($this->organization, 10000, 'purchase', null, 'test-pack');
    }

    protected function tearDown(): void
    {
        if ($this->connectionName !== null && $this->originalConnectionConfiguration !== null) {
            DB::purge($this->connectionName);
            config()->set('database.connections.'.$this->connectionName, $this->originalConnectionConfiguration);
            DB::connection($this->connectionName);
        }
        parent::tearDown();
    }

    public function test_completed_request_replays_same_response_and_charges_actual_calls_once(): void
    {
        $payload = $this->quote();
        $started = $this->lifecycle->start($this->organization, $this->actor, null, $payload);
        $request = $started['request'];
        $attempt = $this->lifecycle->beforeProviderCall($request, $this->actor, 100, 100);
        $usage = ['input_tokens' => 100, 'output_tokens' => 100, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        $this->lifecycle->recordProviderUsage($request, $usage, $attempt);
        $this->lifecycle->recordProviderUsage($request, $usage, $attempt);
        $response = $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Подтверждённый ответ', 'metadata' => ['source_refs' => []]]]);
        $replay = $this->lifecycle->start($this->organization, $this->actor, null, $payload);
        $this->assertSameJsonObject($response, $replay['response']);
        $this->assertSame($request->id, $replay['request']->id);
        $this->assertSame(50, $response['credit_usage']['charged_minor']);
        $this->assertSame(9950, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(1, AICreditReservation::query()->count());
        $this->assertSame(1, AICreditProviderUsage::query()->count());
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $this->assertSame(8100, $this->credits->successfulCostMicroRub(AICreditReservation::query()->findOrFail($request->reservation_id)));
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        $rootReplay = $this->providerService($provider)->ask($payload['message'], $this->organization->id, $this->actor, null, $payload);
        $this->assertSameJsonObject($response, $rootReplay);
        $this->assertSame(1, AICreditProviderUsage::query()->count());
    }

    #[DataProvider('incompleteProviderResults')]
    public function test_incomplete_ask_releases_reservation_and_records_actual_cost_without_publishing(string $reason, string $content, bool $providerThrows): void
    {
        $payload = $this->quote();
        $response = [
            'content' => $content, 'response_status' => 'incomplete', 'incomplete_reason' => $reason, 'finish_reason' => 'length',
            'input_tokens' => 80, 'output_tokens' => 128, 'tokens_used' => 208, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna',
            'tool_calls' => [['id' => 'call_partial', 'type' => 'function', 'function' => ['name' => 'read_project', 'arguments' => '{"id":']]],
        ];
        $provider = $this->createMock(LLMProviderInterface::class);
        $invocation = $provider->expects($this->once())->method('chat');
        if ($providerThrows) {
            $invocation->willThrowException(new AssistantResponseIncomplete($response));
        } else {
            $invocation->willReturn($response);
        }
        $service = $this->providerService($provider);
        $service->probeProviderDuringAsk = true;
        $this->assertOperationThrows(AssistantResponseIncomplete::class, fn () => $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload));

        $request = AssistantRequest::query()->where('request_id', $payload['request_id'])->sole();
        $usage = AICreditProviderUsage::query()->sole();
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $this->assertSame('failed', $request->status);
        $this->assertNull($request->response);
        $this->assertSame(1, $request->calls_used);
        $this->assertSame(0, Message::query()->where('role', 'assistant')->count());
        $this->assertSame(9720, $usage->cost_micro_rub);
        $this->assertFalse($usage->is_successful);
        $this->assertSame(80, $usage->metadata['input_tokens']);
        $this->assertSame(128, $usage->metadata['output_tokens']);
        $this->assertSame(0, $this->credits->successfulCostMicroRub($reservation));
        $this->assertSame(0, (int) $reservation->consumed_minor);
        $this->assertSame('cancelled', $reservation->status);
        $this->assertNotNull($reservation->cancelled_at);
        $this->assertSame(0, (int) $reservation->allocations()->sum('consumed_minor'));
        $closing = AICreditLedgerEntry::query()->where('reference_type', 'reservation')->where('reference_id', $reservation->public_id)->where('type', 'consume');
        $this->assertSame(0, $closing->sole()->amount_minor);
        $this->assertSame(0, (int) $closing->sum('amount_minor'));
        $release = AICreditLedgerEntry::query()->where('reference_type', 'reservation')->where('reference_id', $reservation->public_id)->where('type', 'release')->sole();
        $this->assertSame(0, $release->amount_minor);
        $balance = $this->credits->balance($this->organization);
        $this->assertSame(10000, $balance['available_minor']);
        $this->assertSame(10000, $balance['total_minor']);
        $this->assertSame(0, $balance['reserved_minor']);
    }

    public static function incompleteProviderResults(): array
    {
        return [
            'transport throws after partial text' => ['max_output_tokens', 'Обрезанный ответ', true],
            'transport throws after empty output' => ['max_output_tokens', '', true],
            'returned incomplete is also blocked' => ['max_output_tokens', 'Обрезанный ответ', false],
            'filter reason does not become useful' => ['content_filter', 'Обрезанный ответ', true],
        ];
    }

    public function test_same_request_id_cannot_mutate_message_context_or_actor(): void
    {
        $payload = $this->quote(['context' => ['entity_refs' => [['type' => 'project', 'id' => 10]], 'filters' => ['state' => 'active']]]);
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $payload)['request'];
        $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Ответ', 'metadata' => ['source_refs' => []]]]);
        foreach ([array_replace($payload, ['message' => 'Другой запрос']), array_replace($payload, ['context' => ['filters' => ['state' => 'closed']]])] as $tampered) {
            $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->start($this->organization, $this->actor, null, $tampered));
        }
        $other = $this->member('Другой');
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->start($this->organization, $other, null, $payload));
        $this->assertSame(1, AssistantRequest::query()->count());
        $this->assertSame(1, AICreditReservation::query()->count());
    }

    public function test_cancel_before_start_leaves_tombstone_and_never_reserves_or_generates(): void
    {
        $payload = $this->quote();
        $this->assertSame('queued', $this->lifecycle->status($payload['request_id'], $this->actor, $this->organization->id)['status']);
        $cancelled = $this->lifecycle->cancel($payload['request_id'], $this->actor, $this->organization->id);
        $this->assertSame('cancelled', $cancelled['status']);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $this->providerService($provider)->ask($payload['message'], $this->organization->id, $this->actor, null, $payload));
        $this->assertSame(0, AICreditReservation::query()->count());
        $this->assertSame(0, AICreditProviderUsage::query()->count());
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_queued_request_replays_without_second_reservation_and_claims_once(): void
    {
        $payload = $this->quote();
        $started = $this->lifecycle->startQueued($this->organization, $this->actor, null, $payload + ['async' => true], 'lk');
        $repeated = $this->lifecycle->startQueued($this->organization, $this->actor, null, $payload + ['async' => true], 'lk');
        $this->assertTrue($started['created']);
        $this->assertFalse($repeated['created']);
        $this->assertSame($started['request']->id, $repeated['request']->id);
        $this->assertSame(1, AICreditReservation::query()->count());
        $this->assertSame($payload['message'], $started['request']->payload['message']);
        $this->assertArrayNotHasKey('async', $started['request']->payload);
        $this->assertSame('lk', $started['request']->surface);
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->status($payload['request_id'], $this->actor, $this->organization->id, 'admin'));
        $this->assertNotNull($this->lifecycle->claimQueued($started['request']->id));
        $this->assertNull($this->lifecycle->claimQueued($started['request']->id));
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->startQueued($this->organization, $this->actor, null, array_replace($payload, ['message' => 'Подмена']), 'lk'));
    }

    public function test_actual_implicit_project_greeting_uses_no_business_plan_rag_or_provider_and_charges_zero(): void
    {
        $payload = $this->quote([
            'message' => 'Привет',
            'profile' => 'normal',
            'context' => [
                'source_module' => 'ai-assistant',
                'entity_refs' => [['type' => 'project', 'id' => 56, 'label' => 'Текущий проект']],
                'ui_state' => ['assistant_path' => '/ai-assistant/chat'],
            ],
        ]);
        $this->assertSame(0, AICreditQuote::query()->where('public_id', $payload['quote_id'])->sole()->max_units_minor);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        $orchestrator = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator::class);
        $orchestrator->expects($this->never())->method('plan');
        $usage = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\UsageTracker::class);
        $usage->expects($this->never())->method('canMakeRequest');
        $usage->expects($this->once())->method('trackRequest')->with($this->organization->id, $this->actor, 0, 0.0);
        $usage->method('getUsageStats')->willReturn([]);
        $service = $this->providerService($provider, null, $orchestrator, $usage);
        $service->failOnRagBuild = true;
        $response = $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload);

        $this->assertSame('completed', $response['status']);
        $this->assertSame('greeting', $response['message']['metadata']['response_kind']);
        $this->assertSame('verified', $response['message']['metadata']['validation_status']);
        $this->assertSame([], $response['message']['metadata']['source_refs']);
        $this->assertSame(0, $response['credit_usage']['charged_minor']);
        $this->assertSame(0, $response['credit_usage']['projected_charge_minor']);
        $this->assertSame(0, $response['credit_usage']['reserved_minor']);
        $this->assertSame(0, AICreditProviderUsage::query()->count());
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_queued_greeting_finishes_without_provider_and_replays_zero_charge(): void
    {
        $payload = $this->quote([
            'message' => 'Привет',
            'profile' => 'normal',
            'context' => ['source_module' => 'ai-assistant', 'entity_refs' => [['type' => 'project', 'id' => 56]], 'ui_state' => ['assistant_path' => '/ai-assistant/chat']],
        ]);
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $payload, 'lk')['request'];
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        $orchestrator = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator::class);
        $orchestrator->expects($this->never())->method('plan');
        $service = $this->providerService($provider, null, $orchestrator);
        $service->failOnRagBuild = true;
        app()->instance(AIAssistantService::class, $service);
        (new ExecuteAssistantChatJob($request->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));

        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame('greeting', $request->fresh()->response['message']['metadata']['response_kind']);
        $this->assertSame(0, $request->fresh()->response['credit_usage']['charged_minor']);
        $this->assertSame(0, $request->fresh()->calls_used);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSameJsonObject($request->fresh()->response, $this->lifecycle->startQueued($this->organization, $this->actor, null, $payload, 'lk')['response']);
    }

    public function test_cancel_queued_request_refunds_before_worker_claim(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $cancelled = $this->lifecycle->cancel($request->request_id, $this->actor, $this->organization->id);
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertNull($this->lifecycle->claimQueued($request->id));
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_expired_queued_lease_recovers_reservation_and_prevents_late_execution(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        try {
            $this->travel(9)->minutes();
            $this->assertSame(1, $this->lifecycle->expireAbandoned());
            $this->assertSame('cancelled', $request->fresh()->status);
            $this->assertSame('request_expired', $request->fresh()->error_code);
            $this->assertNull($this->lifecycle->claimQueued($request->id));
            $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        } finally {
            $this->travelBack();
        }
    }

    public function test_request_deadline_includes_time_spent_in_queue(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        config()->set('ai-assistant.request_deadline_seconds.normal', 120);
        $request->created_at = now()->subSeconds(121);
        $context = $this->lifecycle->createExecutionContext($request, $this->actor);

        $this->expectException(AssistantRequestDeadlineExceeded::class);
        $context->remainingMilliseconds();
    }

    public function test_expired_worker_deadline_fails_and_refunds_before_queue_failure_hook(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $request->forceFill(['created_at' => now()->subSeconds(61)])->save();
        $assistant = $this->mock(AIAssistantService::class);
        $assistant->shouldNotReceive('executeStartedRequest');
        app()->instance(AIAssistantService::class, $assistant);
        $job = new ExecuteAssistantChatJob($request->id);

        $job->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));
        $releaseCount = AICreditLedgerEntry::query()->where('type', 'release')->count();
        $job->failed(null);

        $this->assertSame('failed', $request->fresh()->status);
        $this->assertSame('request_deadline_exceeded', $request->fresh()->error_code);
        $this->assertSame($releaseCount, AICreditLedgerEntry::query()->where('type', 'release')->count());
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_operation_budget_ends_database_read_before_request_deadline(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $request = $this->lifecycle->claimQueued($request->id);
        $context = $this->lifecycle->createExecutionContext($request, $this->actor);
        app()->instance(AssistantRequestExecutionContext::class, $context);
        $context->activate();

        try {
            $this->expectException(AssistantRequestDeadlineExceeded::class);
            $context->withOperationBudget(static function (): void {
                usleep(10_000);
            }, 1);
        } finally {
            $context->restoreDatabaseStatementTimeouts();
            app()->forgetInstance(AssistantRequestExecutionContext::class);
        }
    }

    public function test_cancellation_during_worker_execution_refunds_reservation(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $assistant = $this->mock(AIAssistantService::class);
        $assistant->shouldReceive('executeStartedRequest')->once()->andReturnUsing(function (AssistantRequest $activeRequest, User $actor): array {
            $this->lifecycle->cancel($activeRequest->request_id, $actor, $this->organization->id);
            $this->lifecycle->checkpoint($activeRequest, $actor);

            return [];
        });
        app()->instance(AIAssistantService::class, $assistant);

        (new ExecuteAssistantChatJob($request->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame('request_cancelled', $request->fresh()->error_code);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_generic_service_failure_is_logged_and_rethrown_after_request_is_failed(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $failure = new RuntimeException('private provider detail');
        $assistant = $this->mock(AIAssistantService::class);
        $assistant->shouldReceive('executeStartedRequest')->once()->andReturnUsing(function (AssistantRequest $activeRequest) use ($failure): array {
            $this->lifecycle->fail($activeRequest, 'request_failed');
            throw $failure;
        });
        app()->instance(AIAssistantService::class, $assistant);
        Log::shouldReceive('error')->once()->withArgs(function (string $event, array $context) use ($failure, $request): bool {
            return $event === 'ai.assistant.queued_request_failed'
                && $context === [
                    'request_id' => $request->request_id,
                    'exception_class' => $failure::class,
                    'exception_file' => $failure->getFile(),
                    'exception_line' => $failure->getLine(),
                ];
        });

        try {
            (new ExecuteAssistantChatJob($request->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));
            $this->fail('The original service exception must be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame('failed', $request->fresh()->status);
        $this->assertSame('request_failed', $request->fresh()->error_code);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_owned_request_can_be_cancelled_after_organization_switch_without_exposing_old_response(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $otherOrganization = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Новая организация']));
        DB::table('organization_user')->insert(['organization_id' => $otherOrganization->id, 'user_id' => $this->actor->id, 'is_active' => true, 'is_owner' => false]);
        $this->actor->forceFill(['current_organization_id' => $otherOrganization->id])->save();

        $status = $this->lifecycle->cancelOwned($request->request_id, $this->actor, 'lk');

        $this->assertSame(['request_id' => $request->request_id, 'status' => 'cancelled', 'stage' => 'cancelled'], $status);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(0, $this->credits->balance($otherOrganization)['reserved_minor']);
        $this->assertSame('request_cancelled', $request->fresh()->error_code);
        $this->assertNull($request->fresh()->response);
    }

    public function test_worker_stops_and_refunds_when_actor_switches_organization(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $otherOrganization = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Новая организация']));
        DB::table('organization_user')->insert(['organization_id' => $otherOrganization->id, 'user_id' => $this->actor->id, 'is_active' => true, 'is_owner' => false]);
        $assistant = $this->mock(AIAssistantService::class);
        $assistant->shouldReceive('executeStartedRequest')->once()->andReturnUsing(function (AssistantRequest $activeRequest, User $actor) use ($otherOrganization): array {
            $actor->forceFill(['current_organization_id' => $otherOrganization->id])->save();
            $this->lifecycle->checkpoint($activeRequest, $actor);

            return [];
        });
        app()->instance(AIAssistantService::class, $assistant);

        (new ExecuteAssistantChatJob($request->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame('request_cancelled', $request->fresh()->error_code);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_cancel_owned_denies_another_actor(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $other = $this->member('Другой автор');

        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->cancelOwned($request->request_id, $other, 'lk'));
        $this->assertSame('running', $request->fresh()->status);
        $this->assertSame(9800, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_duplicate_worker_job_executes_only_once(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $assistant = $this->mock(AIAssistantService::class);
        $assistant->shouldReceive('executeStartedRequest')->once()->andReturn([]);
        $job = new ExecuteAssistantChatJob($request->id);
        $policy = app(AssistantDataAccessPolicy::class);
        app()->instance(AIAssistantService::class, $assistant);
        $job->handle($this->lifecycle, $policy);
        $job->handle($this->lifecycle, $policy);
        $this->assertNotNull($request->fresh()->started_at);
        $this->lifecycle->stage($request, $this->actor, 'reading');
        $this->assertSame('reading', $request->fresh()->stage);
    }

    public function test_sequential_jobs_use_their_own_trusted_surface_in_scoped_consumers(): void
    {
        $lk = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $admin = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $seenPolicies = [];
        $assistant = $this->mock(AIAssistantService::class);
        $assistant->shouldReceive('executeStartedRequest')->twice()->andReturnUsing(function (AssistantRequest $request) use (&$seenPolicies): array {
            $policy = app(AssistantDataAccessPolicy::class);
            $surface = (new \ReflectionProperty(AssistantDataAccessPolicy::class, 'trustedSurface'))->getValue($policy);
            $this->assertSame(KnowledgeSurface::from($request->surface), $surface);
            $retriever = app(RagRetriever::class);
            $this->assertSame($policy, (new \ReflectionProperty($retriever, 'accessPolicy'))->getValue($retriever));
            $seenPolicies[] = $policy;
            return [];
        });
        app()->instance(AIAssistantService::class, $assistant);
        (new ExecuteAssistantChatJob($lk->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));
        app()->forgetScopedInstances();
        app()->instance(AIAssistantService::class, $assistant);
        (new ExecuteAssistantChatJob($admin->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));
        $this->assertNotSame($seenPolicies[0], $seenPolicies[1]);
    }

    public function test_revoked_access_prevents_queued_claim_and_refunds(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'mobile')['request'];
        $this->assistantEnabled = false;
        $this->assertNull($this->lifecycle->claimQueued($request->id));
        $this->assertSame('failed', $request->fresh()->status);
        $this->assertSame('access_revoked', $request->fresh()->error_code);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_completed_status_checks_source_access_and_payload_expires_after_90_days(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $response = $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Ответ', 'metadata' => ['source_refs' => []]]]);
        $this->assertSameJsonObject($response, $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'lk')['response']);
        $tampered = $response;
        $tampered['message']['metadata']['source_refs'] = [['entity_type' => 'unknown_entity', 'entity_id' => 123]];
        $request->forceFill(['response' => $tampered, 'created_at' => now()->subDays(91)])->save();
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'lk'));
        $this->assertSame(1, $this->lifecycle->purgeExpiredPayloads());
        $this->assertNull($request->fresh()->payload);
        $this->assertNotNull($request->fresh()->response);
    }

    public function test_legacy_request_without_surface_never_exposes_saved_response_by_get(): void
    {
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Старый ответ', 'metadata' => ['source_refs' => []]]]);
        foreach (['lk', 'admin', 'mobile'] as $surface) {
            $status = $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, $surface);
            $this->assertSame('completed', $status['status']);
            $this->assertArrayNotHasKey('response', $status);
        }
    }

    public function test_surface_scoped_sync_request_keeps_running_conflict_and_completed_replay(): void
    {
        $payload = $this->quote();
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $payload, 'admin')['request'];
        $this->assertNull($request->payload);
        $this->assertOperationThrows(AssistantRequestInProgress::class, fn () => $this->lifecycle->start($this->organization, $this->actor, null, $payload, 'admin'));
        $response = $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Ответ', 'metadata' => ['source_refs' => []]]]);
        $this->assertSameJsonObject($response, $this->lifecycle->start($this->organization, $this->actor, null, $payload, 'admin')['response']);
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->start($this->organization, $this->actor, null, $payload, 'lk'));
    }

    public function test_cancellation_prevents_next_root_provider_call_and_releases_reservation(): void
    {
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->once())->method('chat')->willReturn(['content' => 'Ответ', 'input_tokens' => 100, 'output_tokens' => 10, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna']);
        $service = $this->providerService($provider);
        $service->invokeProvider($request, $this->actor, [['role' => 'system', 'content' => 'Правила'], ['role' => 'user', 'content' => 'Запрос']]);
        $this->lifecycle->cancel($request->request_id, $this->actor, $this->organization->id);
        $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $service->invokeProvider($request, $this->actor, [['role' => 'user', 'content' => 'Следующий круг']]));
        $this->lifecycle->fail($request);
        $this->assertSame(1, $request->fresh()->calls_used);
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(1, AICreditProviderUsage::query()->count());
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_cancellation_after_provider_attempt_reservation_prevents_first_http_call(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $request = $this->lifecycle->claimQueued($request->id);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        $usage = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\UsageTracker::class);
        $usage->method('canMakeRequest')->willReturn(true);
        $usage->method('getUsageStats')->willReturn([]);
        $usage->expects($this->never())->method('recordUsage');
        $context = $this->lifecycle->createExecutionContext($request, $this->actor);
        app()->instance(AssistantRequestExecutionContext::class, $context);
        $context->activate();
        Event::listen(AssistantRequestChanged::class, function (AssistantRequestChanged $event) use ($request): void {
            if (($event->broadcastWith()['request_id'] ?? null) === $request->request_id
                && $request->fresh()->calls_used > 0) {
                $this->lifecycle->cancel($request->request_id, $this->actor, $this->organization->id, 'lk');
            }
        });

        try {
            $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $this->providerService($provider, usageOverride: $usage)
                ->invokeProvider($request, $this->actor, [['role' => 'user', 'content' => 'Запрос']]));
            $this->lifecycle->fail($request);
        } finally {
            Event::forget(AssistantRequestChanged::class);
            $context->restoreDatabaseStatementTimeouts();
            app()->forgetInstance(AssistantRequestExecutionContext::class);
        }

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->calls_used);
        $this->assertSame(0, AICreditProviderUsage::query()->count());
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_late_provider_result_is_journaled_and_cancelled_request_refunds_once(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'lk')['request'];
        $request = $this->lifecycle->claimQueued($request->id);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->once())->method('chat')->willReturnCallback(function () use ($request): array {
            $this->lifecycle->cancel($request->request_id, $this->actor, $this->organization->id, 'lk');

            return ['content' => 'Ответ', 'input_tokens' => 100, 'output_tokens' => 10, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        });
        $usage = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\UsageTracker::class);
        $usage->method('canMakeRequest')->willReturn(true);
        $usage->method('getUsageStats')->willReturn([]);
        $usage->expects($this->once())->method('recordUsage');
        $context = $this->lifecycle->createExecutionContext($request, $this->actor);
        app()->instance(AssistantRequestExecutionContext::class, $context);
        $context->activate();

        try {
            $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $this->providerService($provider, usageOverride: $usage)
                ->invokeProvider($request, $this->actor, [['role' => 'user', 'content' => 'Запрос']]));
            $context->withCleanupBudget(fn () => $this->lifecycle->fail($request, 'request_cancelled'));
        } finally {
            $context->restoreDatabaseStatementTimeouts();
            app()->forgetInstance(AssistantRequestExecutionContext::class);
        }

        $this->assertSame('cancelled', $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->calls_used);
        $this->assertSame(1, AICreditProviderUsage::query()->count());
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_slow_rag_can_reach_first_provider_call_after_five_minutes(): void
    {
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $this->lifecycle->stage($request, $this->actor, 'reading');
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->once())->method('chat')->willReturn([
            'content' => 'Ответ', 'input_tokens' => 100, 'output_tokens' => 10,
            'provider' => 'test-fixture', 'model' => 'gpt-6-luna',
        ]);

        try {
            $this->travel(5)->minutes();
            $this->providerService($provider)->invokeProvider($request, $this->actor, [['role' => 'user', 'content' => 'Запрос']]);
            $this->assertSame(1, $request->fresh()->calls_used);
        } finally {
            $this->travelBack();
        }
    }

    public function test_expired_lease_still_blocks_provider_and_cannot_be_renewed(): void
    {
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');

        try {
            $this->travel(9)->minutes();
            $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $this->lifecycle->stage($request, $this->actor, 'reading'));
            $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $this->providerService($provider)->invokeProvider($request, $this->actor, [['role' => 'user', 'content' => 'Запрос']]));
        } finally {
            $this->travelBack();
        }
    }

    public function test_call_limit_and_approved_cost_are_checked_before_starting_another_call(): void
    {
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $this->assertSame(1, $this->lifecycle->beforeProviderCall($request, $this->actor, 1, 1));
        $this->assertSame(2, $this->lifecycle->beforeProviderCall($request, $this->actor, 1, 1));
        $this->assertOperationThrows(AssistantBudgetExceeded::class, fn () => $this->lifecycle->beforeProviderCall($request, $this->actor, 1, 1));
        $this->assertSame(2, $request->fresh()->calls_used);
        $another = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $this->assertOperationThrows(AssistantBudgetExceeded::class, fn () => $this->lifecycle->beforeProviderCall($another, $this->actor, 100000, 100000));
        $this->assertSame(0, $another->fresh()->calls_used);
        $reservation = AICreditReservation::query()->findOrFail($another->reservation_id);
        $this->credits->recordProviderCost($reservation, $this->credits->approvedCostMicroRub($reservation), 'test-fixture', 'gpt-6-luna', 'assistant_chat', ['usage_key' => 'spent'], true);
        $this->assertOperationThrows(AssistantBudgetExceeded::class, fn () => $this->lifecycle->beforeProviderCall($another, $this->actor, 1, 1));
    }

    public function test_quote_snapshot_keeps_root_input_output_limits_and_pricing_after_configuration_changes(): void
    {
        $payload = $this->quote();
        config()->set('ai-assistant-credits.profiles.short', ['input_tokens' => 1000, 'output_tokens' => 100, 'max_calls' => 1]);
        config()->set('ai-assistant-credits.pricing.input_micro_rub_per_million', 999999999);
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $payload)['request'];
        $this->assertSameJsonObject(['input_tokens' => 8192, 'output_tokens' => 1024, 'max_calls' => 2], $this->lifecycle->limits($request));
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->once())->method('chat')->with($this->callback(static fn (array $messages): bool => end($messages)['content'] === str_repeat('я', 4000)),
            $this->callback(static fn (array $options): bool => $options['max_completion_tokens'] === 1024 && $options['budget_limits']['input_tokens'] === 8192 && $options['estimated_input_tokens'] <= 8192))
            ->willReturn(['content' => 'Ответ', 'input_tokens' => 1, 'output_tokens' => 1, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna']);
        $this->providerService($provider)->invokeProvider($request, $this->actor, [['role' => 'system', 'content' => 'Обязательные правила'], ['role' => 'user', 'content' => str_repeat('я', 4000)]]);
        $this->assertSame(81, AICreditProviderUsage::query()->sole()->cost_micro_rub);
    }

    public function test_role_withdrawal_denies_completed_replay_and_active_generation(): void
    {
        $payload = $this->quote();
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $payload)['request'];
        $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Ответ', 'metadata' => ['source_refs' => []]]]);
        $running = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
        $this->assistantEnabled = false;
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->start($this->organization, $this->actor, null, $payload));
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->beforeProviderCall($running, $this->actor, 1, 1));
        $this->assertSame(0, $running->fresh()->calls_used);
    }

    public function test_parallel_editors_are_blocked_in_same_conversation_and_viewers_cannot_start(): void
    {
        $conversation = $this->conversations->createConversation($this->organization->id, $this->actor, 'Общий чат');
        $editor = $this->member('Редактор');
        $viewer = $this->member('Читатель');
        foreach ([[$editor, 'editor'], [$viewer, 'viewer']] as [$member, $role]) {
            DB::table('ai_conversation_participants')->insert(['conversation_id' => $conversation->id, 'user_id' => $member->id, 'added_by_user_id' => $this->actor->id, 'role' => $role]);
        }
        $request = $this->lifecycle->start($this->organization, $this->actor, $conversation->id, $this->quote([], $this->actor, $conversation->id))['request'];
        $second = $this->quote([], $editor, $conversation->id);
        $this->assertOperationThrows(AssistantRequestInProgress::class, fn () => $this->lifecycle->start($this->organization, $editor, $conversation->id, $second));
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->start($this->organization, $viewer, $conversation->id, $this->quote([], $viewer, $conversation->id)));
        $this->lifecycle->fail($request);
        $started = $this->lifecycle->start($this->organization, $editor, $conversation->id, $second);
        $this->assertSame($editor->id, $started['request']->user_id);
        DB::table('ai_conversation_participants')->where('conversation_id', $conversation->id)->where('user_id', $editor->id)->update(['role' => 'viewer']);
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->beforeProviderCall($started['request'], $editor, 1, 1));
    }

    public function test_calibration_failure_or_profile_overflow_stops_next_provider_call(): void
    {
        foreach ([['persisted' => false], ['persisted' => true, 'profile_input_exceeded' => true]] as $calibration) {
            $request = $this->lifecycle->start($this->organization, $this->actor, null, $this->quote())['request'];
            $attempt = $this->lifecycle->beforeProviderCall($request, $this->actor, 1, 1);
            $this->lifecycle->recordProviderUsage($request, ['input_tokens' => 1, 'output_tokens' => 1, 'token_calibration' => $calibration], $attempt);
            $provider = $this->createMock(LLMProviderInterface::class);
            $provider->expects($this->never())->method('chat');
            $this->assertOperationThrows(AssistantBudgetExceeded::class, fn () => $this->providerService($provider)->invokeProvider($request, $this->actor, [['role' => 'user', 'content' => 'Следующий вызов']]));
            $this->assertSame(1, $request->fresh()->calls_used);
        }
    }

    public function test_completed_replay_checks_current_source_access_and_failed_calls_stay_internal(): void
    {
        $payload = $this->quote();
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $payload)['request'];
        $this->lifecycle->recordProviderUsage($request, ['input_tokens' => 100, 'output_tokens' => 100], 1, false);
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $this->assertSame(0, $this->credits->successfulCostMicroRub($reservation));
        $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Ответ', 'metadata' => ['source_refs' => []]]], false);
        $request->refresh();
        $response = $request->response;
        $response['message']['metadata']['source_refs'] = [['entity_type' => 'unknown_entity', 'entity_id' => 123]];
        $request->forceFill(['response' => $response])->save();
        $this->assertOperationThrows(AuthorizationException::class, fn () => $this->lifecycle->start($this->organization, $this->actor, null, $payload));
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_pending_answer_is_hidden_until_atomic_charge_and_publication_and_replays_once(): void
    {
        $conversation = $this->conversations->createConversation($this->organization->id, $this->actor, 'Публикация');
        $payload = $this->quote([], $this->actor, $conversation->id);
        $request = $this->lifecycle->start($this->organization, $this->actor, $conversation->id, $payload)['request'];
        $message = $this->pendingMessage($request, $conversation);
        $this->assertCount(0, $this->conversations->getHistory($conversation, 10, $this->actor));
        $published = 0;
        $response = $this->lifecycle->complete($request, $this->actor, ['message' => ['id' => $message->id, 'content' => $message->content, 'metadata' => $message->metadata]], true, function () use (&$published, $message): void {
            $published++;
            $this->assertSame('completed', $message->fresh()->metadata['request_state']);
            $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        });
        $this->assertSame(1, $published);
        $this->assertSame('completed', $response['message']['metadata']['request_state']);
        $this->assertCount(1, $this->conversations->getHistory($conversation, 10, $this->actor));
        $replay = $this->lifecycle->start($this->organization, $this->actor, $conversation->id, $payload);
        $this->assertSameJsonObject($response, $replay['response']);
        $this->assertSame(1, $published);
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
    }

    public function test_cancellation_before_publication_removes_pending_answer_and_charges_nothing(): void
    {
        $conversation = $this->conversations->createConversation($this->organization->id, $this->actor, 'Отмена публикации');
        $request = $this->lifecycle->start($this->organization, $this->actor, $conversation->id, $this->quote([], $this->actor, $conversation->id))['request'];
        $message = $this->pendingMessage($request, $conversation);
        $this->lifecycle->cancel($request->request_id, $this->actor, $this->organization->id);
        $this->assertOperationThrows(AssistantRequestCancelled::class, fn () => $this->lifecycle->complete($request, $this->actor, ['message' => ['id' => $message->id, 'metadata' => $message->metadata]]));
        $this->lifecycle->fail($request);
        $this->assertNull(Message::query()->find($message->id));
        $this->assertCount(0, $this->conversations->getHistory($conversation, 10, $this->actor));
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $this->assertSame('cancelled', $reservation->status);
        $this->assertSame(0, $reservation->consumed_minor);
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $this->assertSame(0, AICreditLedgerEntry::query()->where('type', 'consume')->sole()->amount_minor);
        $this->lifecycle->fail($request);
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
    }

    public function test_publication_callback_failure_rolls_back_charge_message_and_completed_request(): void
    {
        $conversation = $this->conversations->createConversation($this->organization->id, $this->actor, 'Атомарность');
        $request = $this->lifecycle->start($this->organization, $this->actor, $conversation->id, $this->quote([], $this->actor, $conversation->id))['request'];
        $message = $this->pendingMessage($request, $conversation);
        $this->assertOperationThrows(\RuntimeException::class, fn () => $this->lifecycle->complete($request, $this->actor, ['message' => ['id' => $message->id, 'metadata' => $message->metadata]], true, static function (): void {
            throw new \RuntimeException('publication_fixture_failure');
        }));
        $this->assertSame('running', $request->fresh()->status);
        $this->assertSame('pending', $message->fresh()->metadata['request_state']);
        $this->assertSame('reserved', AICreditReservation::query()->findOrFail($request->reservation_id)->status);
        $this->assertSame(0, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $this->lifecycle->fail($request);
        $this->assertNull($message->fresh());
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
    }

    private function pendingMessage(AssistantRequest $request, Conversation $conversation): Message
    {
        return $this->conversations->addMessage($conversation, 'assistant', 'Ответ ожидает публикации', 0, 'gpt-6-luna', [
            'request_id' => $request->request_id, 'actor_user_id' => $this->actor->id,
            'request_state' => 'pending', 'validation_status' => 'verified', 'source_refs' => [],
        ]);
    }

    private function member(string $name): User
    {
        $user = User::withoutEvents(fn () => User::query()->create(['name' => $name, 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true, 'current_organization_id' => $this->organization->id]));
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $user->id, 'is_active' => true, 'is_owner' => true]);

        return $user;
    }

    #[DataProvider('projectedChargeModes')]
    public function test_completion_reports_projected_charge_without_changing_shadow_debit_or_replay_journal(bool $charging, bool $useful): void
    {
        config()->set('ai-assistant-credits.enforce', $charging);
        $payload = $this->quote();
        $request = $this->lifecycle->start($this->organization, $this->actor, null, $payload)['request'];
        $attempt = $this->lifecycle->beforeProviderCall($request, $this->actor, 100, 100);
        $this->lifecycle->recordProviderUsage($request, ['input_tokens' => 100, 'output_tokens' => 100,
            'provider' => 'test-fixture', 'model' => 'gpt-6-luna'], $attempt);
        $response = $this->lifecycle->complete($request, $this->actor, ['validation_status' => 'unverified',
            'message' => ['content' => $useful ? 'Готовый ответ' : 'Требуется уточнение', 'metadata' => ['needs_clarification' => ! $useful]],
            'source_refs' => []], $useful);
        $expectedProjected = $useful ? 50 : 0;
        $expectedCharged = $charging ? $expectedProjected : 0;
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $closing = AICreditLedgerEntry::query()->where('reference_type', 'reservation')->where('reference_id', $reservation->public_id)->where('type', 'consume');

        $this->assertSame($expectedProjected, $response['credit_usage']['projected_charge_minor']);
        $this->assertSame($expectedCharged, $response['credit_usage']['charged_minor']);
        $this->assertSame($charging, $response['credit_usage']['charging_enabled']);
        $this->assertSame($expectedCharged, $reservation->consumed_minor);
        $this->assertSame(-$expectedCharged, $closing->sole()->amount_minor);
        $this->assertSame(8100, AICreditProviderUsage::query()->sole()->cost_micro_rub);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(10000 - $expectedCharged, $this->credits->balance($this->organization)['available_minor']);
        $replay = $this->lifecycle->start($this->organization, $this->actor, null, $payload)['response'];
        $this->assertSameJsonObject($response, $replay);
        $this->assertSame(1, $closing->count());
        $this->assertSame(1, AICreditProviderUsage::query()->count());
    }

    public static function projectedChargeModes(): array
    {
        return [
            'shadow useful answer' => [false, true],
            'shadow clarification' => [false, false],
            'charged useful answer' => [true, true],
            'charged clarification' => [true, false],
        ];
    }

    #[DataProvider('deniedProviderRequests')]
    public function test_actual_ask_acl_refusal_is_free_and_retains_provider_usage_and_idempotent_receipt(string $query): void
    {
        $payload = $this->quote(['message' => $query]);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static fn (User $user, string $permission): bool => $permission === 'ai_assistant.chat');
        $authorization->method('forCurrentChecks')->willReturnSelf();
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect([new Module(['slug' => 'ai-assistant']), new Module(['slug' => 'contract-management'])]));
        $policy = new AssistantDataAccessPolicy($authorization, $this->createMock(UserProjectAccessService::class), $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $permissions = new AIPermissionChecker($authorization);
        $this->app->instance(AIPermissionChecker::class, $permissions);
        $this->assertTrue($permissions->canUseAssistant($this->actor, $this->organization->id));
        $this->assertFalse($permissions->canExecuteTool($this->actor, 'assistant_domain_read', ['domain' => 'contracts', 'id' => 123]));
        $tool = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
        $tool->method('getName')->willReturn('assistant_domain_read');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['domain' => ['type' => 'string'], 'id' => ['type' => 'integer']]]);
        $tool->expects($this->never())->method('execute');
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $usage = ['input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        $provider->expects($this->exactly(2))->method('chat')->willReturnOnConsecutiveCalls(
            $usage + ['content' => '', 'tool_calls' => [['id' => 'acl-read', 'function' => ['name' => 'assistant_domain_read', 'arguments' => '{"domain":"contracts","id":123}']]]],
            $usage + ['content' => 'У вас нет доступа к данным договора.']
        );
        $access = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver::class);
        $orchestrator = new \App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator(new \App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry, $access);
        $service = $this->providerService($provider, $registry, $orchestrator);

        $response = $service->ask($query, $this->organization->id, $this->actor, null, $payload);

        $this->assertSame('access_denied', $response['message']['metadata']['outcome']);
        $this->assertTrue($response['message']['metadata']['access_denied']);
        $this->assertSame(0, $response['credit_usage']['charged_minor']);
        $this->assertSame(0, $response['credit_usage']['projected_charge_minor']);
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(2, AICreditProviderUsage::query()->count());
        $this->assertGreaterThan(0, AICreditProviderUsage::query()->sum('cost_micro_rub'));
        $this->assertSame(0, (int) AICreditReservation::query()->sole()->consumed_minor);
        $closing = AICreditLedgerEntry::query()->where('type', 'consume');
        $this->assertSame(0, $closing->sole()->amount_minor);
        $this->assertSameJsonObject($response, $service->ask($query, $this->organization->id, $this->actor, null, $payload));
        $this->assertSame(1, $closing->count());
        $this->assertSame(2, AICreditProviderUsage::query()->count());
    }

    public static function deniedProviderRequests(): array
    {
        return [['Прочитай текст договора и покажи его сумму'], ['Покажи описание договора']];
    }

    #[DataProvider('incompleteFinancialTools')]
    public function test_actual_ask_incomplete_financial_source_is_free_and_keeps_provider_journal(string $name, array $toolResponse): void
    {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturn(true);
        $this->app->instance(AIPermissionChecker::class, new AIPermissionChecker($authorization));
        $tool = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->once())->method('execute')->willReturn($toolResponse);
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $usage = ['input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        $provider->expects($this->exactly(2))->method('chat')->willReturnOnConsecutiveCalls(
            $usage + ['content' => '', 'tool_calls' => [['id' => 'financial-incomplete', 'function' => ['name' => $name, 'arguments' => '{}']]]],
            $usage + ['content' => 'Вот полный ответ по финансовому вопросу.']
        );
        $service = $this->providerService($provider, $registry);
        $payload = $this->quote(['message' => 'Покажи точные суммы проекта']);
        $response = $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload);
        $this->assertSame('insufficient_data', $response['message']['metadata']['outcome']);
        $this->assertFalse($response['message']['metadata']['service_error']);
        $this->assertSame(0, $response['credit_usage']['charged_minor']);
        $this->assertSame(0, $response['credit_usage']['projected_charge_minor']);
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(2, AICreditProviderUsage::query()->count());
        $this->assertGreaterThan(0, AICreditProviderUsage::query()->sum('cost_micro_rub'));
        $this->assertSame(0, (int) AICreditReservation::query()->sole()->consumed_minor);
        $this->assertSameJsonObject($response, $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload));
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $this->assertSame(2, AICreditProviderUsage::query()->count());
    }

    public static function incompleteFinancialTools(): array
    {
        return [
            ['get_published_report_financial_evidence', ['status' => 'insufficient_data', 'useful' => false, 'source_covered' => false, 'source_refs' => []]],
            ['get_live_project_financial_evidence', ['status' => 'partial', 'useful' => false, 'source_covered' => true, 'unavailable_fields' => ['plan_revenue'], 'source_refs' => []]],
        ];
    }

    public function test_last_budgeted_provider_call_executes_one_read_and_publishes_only_verified_facts(): void
    {
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = $this->mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'ai-assistant']), new Module(['slug' => 'users'])]));
        $policy = new AssistantDataAccessPolicy($authorization, $this->mock(UserProjectAccessService::class), $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $permissions = $this->mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->andReturn(true);
        $permissions->shouldReceive('canExecuteTool')->andReturn(true);
        $permissions->shouldReceive('isMutationTool')->andReturn(false);
        $this->conversations = new ConversationManager($policy);
        $this->lifecycle = new AssistantRequestLifecycle($this->credits, $permissions, $this->conversations, $policy);

        $fetchedAt = now()->toISOString();
        $reference = ['entity_type' => 'user', 'entity_id' => $this->actor->id, 'organization_id' => $this->organization->id,
            'content_scope' => 'structured', 'checked_fields' => ['name', 'is_active'], 'required_permissions' => [],
            'required_domains' => ['people'], 'fetched_at' => $fetchedAt];
        $row = ['entity_type' => 'user', 'entity_id' => $this->actor->id, 'fields' => ['name' => $this->actor->name, 'is_active' => true],
            'source_ref' => $reference, 'source_version' => null];
        $row['version'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        $proof = AssistantStructuredFactFormatter::payload([$row], $fetchedAt);

        $registry = new AIToolRegistry;
        $discovery = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
        $discovery->method('getName')->willReturn('assistant_domain_discover_capabilities');
        $discovery->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $discovery->expects($this->once())->method('execute')->willReturn(['capabilities' => ['people']]);
        $registry->registerTool($discovery);
        $reader = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
        $reader->method('getName')->willReturn('assistant_domain_read');
        $reader->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['domain' => ['type' => 'string']]]);
        $reader->expects($this->once())->method('execute')->willReturn($proof);
        $registry->registerTool($reader);

        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $usage = ['input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        $provider->expects($this->exactly(2))->method('chat')->willReturnOnConsecutiveCalls(
            $usage + ['content' => '', 'tool_calls' => [['id' => 'discover', 'function' => ['name' => 'assistant_domain_discover_capabilities', 'arguments' => '{}']]]],
            $usage + ['content' => 'Неподтверждённое утверждение', 'tool_calls' => [['id' => 'read', 'function' => ['name' => 'assistant_domain_read', 'arguments' => '{"domain":"people"}']]]]
        );
        $payload = $this->quote(['message' => 'Покажи текущий статус пользователя']);
        $service = $this->providerService($provider, $registry);

        $response = $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload);

        $this->assertSameJsonObject(['source_refs' => [$reference]], ['source_refs' => $response['message']['metadata']['source_refs']]);
        $this->assertSame('partial', $response['message']['metadata']['validation_status']);
        $this->assertFalse($response['message']['metadata']['needs_clarification']);
        $this->assertStringContainsString($this->actor->name, $response['message']['content']);
        $this->assertStringNotContainsString('Неподтверждённое', $response['message']['content']);
        $this->assertSame(2, AssistantRequest::query()->sole()->calls_used);
        $this->assertSame(2, AICreditProviderUsage::query()->count());
        $this->assertSameJsonObject($response, $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload));
    }

    public function test_last_budgeted_provider_call_cannot_execute_report_export_or_publish_unverified_content(): void
    {
        $permissions = app(AIPermissionChecker::class);
        $permissions->shouldReceive('canExecuteTool')->andReturn(true);
        $permissions->shouldReceive('isMutationTool')->andReturn(false);
        $registry = new AIToolRegistry;
        foreach (['assistant_domain_discover_capabilities', 'generate_operational_pdf_report'] as $name) {
            $tool = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
            $tool->expects($name === 'assistant_domain_discover_capabilities' ? $this->once() : $this->never())
                ->method('execute')->willReturn(['status' => 'ok']);
            $registry->registerTool($tool);
        }
        $orchestrator = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator::class);
        $orchestrator->method('plan')->willReturn(['request' => ['context' => [], 'allow_actions' => true],
            'task_type' => 'summary', 'access_context_public' => [], 'capability' => ['id' => 'reports']]);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $usage = ['input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        $provider->expects($this->exactly(2))->method('chat')->willReturnOnConsecutiveCalls(
            $usage + ['content' => '', 'tool_calls' => [['id' => 'discover', 'function' => ['name' => 'assistant_domain_discover_capabilities', 'arguments' => '{}']]]],
            $usage + ['content' => 'Отчёт создан: https://example.test/secret.pdf',
                'tool_calls' => [['id' => 'export', 'function' => ['name' => 'generate_operational_pdf_report', 'arguments' => '{}']]]]
        );
        $payload = $this->quote(['message' => 'Покажи текущий статус проекта', 'allow_actions' => true]);
        $service = $this->providerService($provider, $registry, $orchestrator);
        $service->ragContextOverride = ['prompt' => '', 'metadata' => ['sources' => [[
            'entity_type' => 'user', 'entity_id' => $this->actor->id, 'organization_id' => $this->organization->id,
        ]]]];

        $response = $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload);

        $this->assertSame([], $response['message']['metadata']['source_refs']);
        $this->assertTrue($response['message']['metadata']['needs_clarification']);
        $this->assertStringNotContainsString('Отчёт создан', $response['message']['content']);
        $this->assertEmpty($response['message']['metadata']['proposed_actions'] ?? []);
        $this->assertArrayNotHasKey('executed_action', $response);
        $this->assertSame(0, $response['credit_usage']['charged_minor']);
        $this->assertSame(2, AssistantRequest::query()->sole()->calls_used);
    }

    #[DataProvider('terminalRequestChanges')]
    public function test_request_change_after_last_provider_call_blocks_terminal_read_and_publication(string $change, string $exception, string $status): void
    {
        $permissions = app(AIPermissionChecker::class);
        $permissions->shouldReceive('canExecuteTool')->andReturn(true);
        $permissions->shouldReceive('isMutationTool')->andReturn(false);
        $registry = new AIToolRegistry;
        foreach (['assistant_domain_discover_capabilities', 'assistant_domain_read'] as $name) {
            $tool = $this->createMock(\App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
            $tool->expects($name === 'assistant_domain_discover_capabilities' ? $this->once() : $this->never())
                ->method('execute')->willReturn(['status' => 'ok']);
            $registry->registerTool($tool);
        }
        $payload = $this->quote(['message' => 'Покажи текущий статус пользователя']);
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->method('getModel')->willReturn('gpt-6-luna');
        $usage = ['input_tokens' => 100, 'output_tokens' => 100, 'tokens_used' => 200, 'provider' => 'test-fixture', 'model' => 'gpt-6-luna'];
        $call = 0;
        $provider->expects($this->exactly(2))->method('chat')->willReturnCallback(function () use (&$call, $usage, $payload, $change): array {
            $call++;
            if ($call === 2) {
                if ($change === 'cancel') {
                    $this->lifecycle->cancel($payload['request_id'], $this->actor, $this->organization->id);
                } else {
                    $this->actor->forceFill(['current_organization_id' => null])->saveQuietly();
                }
            }
            return $usage + ['content' => '', 'tool_calls' => [[
                'id' => $call === 1 ? 'discover' : 'read',
                'function' => ['name' => $call === 1 ? 'assistant_domain_discover_capabilities' : 'assistant_domain_read', 'arguments' => '{}'],
            ]]];
        });

        $this->assertOperationThrows($exception, fn () => $this->providerService($provider, $registry)
            ->ask($payload['message'], $this->organization->id, $this->actor, null, $payload));

        $request = AssistantRequest::query()->sole();
        $this->assertSame($status, $request->status);
        $this->assertSame(2, $request->calls_used);
        $this->assertSame(0, Message::query()->where('role', 'assistant')->count());
        $this->assertSame(0, (int) AICreditReservation::query()->sole()->consumed_minor);
    }

    public static function terminalRequestChanges(): array
    {
        return [
            'cancelled request' => ['cancel', AssistantRequestCancelled::class, 'cancelled'],
            'actor leaves current organization' => ['actor_org', AuthorizationException::class, 'failed'],
        ];
    }

    private function quote(array $overrides = [], ?User $actor = null, ?int $conversationId = null): array
    {
        $payload = array_replace(['request_id' => (string) Str::uuid(), 'message' => 'Проверь текущие данные', 'profile' => 'short', 'allow_actions' => false, 'conversation_id' => $conversationId, 'context' => []], $overrides);
        $quote = $this->credits->quote($this->organization, $actor ?? $this->actor, $payload);

        return $payload + ['quote_id' => $quote['quote_id']];
    }

    public function test_image_ask_uses_current_image_parts_and_actual_receipt_instead_of_free_greeting(): void
    {
        $image = $this->imageFixture();
        $payload = $this->quote(['message' => 'Привет', 'profile' => 'normal', 'attachment_ids' => [$image->public_id]]);
        $this->assertGreaterThan(0, AICreditQuote::query()->where('public_id', $payload['quote_id'])->sole()->max_units_minor);
        $provider = $this->createMock(LLMProviderInterface::class);
        $imageCall = 0;
        $provider->expects($this->exactly(2))->method('chat')->willReturnCallback(function (array $messages, array $options) use (&$imageCall): array {
            $imageCall++;
            if ($imageCall === 2) {
                foreach ($messages as $message) { $this->assertIsString($message['content']); }
                return ['content' => 'Могу помочь разобрать документы и данные.', 'tool_calls' => [], 'input_tokens' => 100, 'output_tokens' => 7, 'tokens_used' => 107, 'provider' => 'test-fixture', 'model' => 'openai/gpt-6-luna', 'provider_usage_available' => true];
            }
            $current = $messages[array_key_last($messages)];
            $this->assertSame('user', $current['role']);
            $this->assertSame(['type' => 'text', 'text' => 'Привет'], $current['content'][0]);
            $this->assertSame('high', $current['content'][1]['image_url']['detail']);
            $this->assertStringStartsWith('data:image/png;base64,', $current['content'][1]['image_url']['url']);
            $this->assertArrayNotHasKey('_trusted_chat_images', $current);
            $this->assertLessThanOrEqual(16384, $options['estimated_input_tokens']);
            return ['content' => 'На изображении виден цветной квадрат.', 'tool_calls' => [], 'input_tokens' => 3020, 'output_tokens' => 7, 'tokens_used' => 3027, 'provider' => 'test-fixture', 'model' => 'openai/gpt-6-luna', 'provider_usage_available' => true];
        });
        $orchestrator = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator::class);
        $orchestrator->method('plan')->willReturn(['request' => ['message' => 'Привет', 'context' => []], 'task_type' => 'summary', 'access_context_public' => [], 'capability' => []]);
        $orchestrator->method('buildPayload')->willReturn(['next_actions' => [], 'needs_clarification' => false]);
        $service = $this->providerService($provider, null, $orchestrator);
        $service->ragContextOverride = ['prompt' => '', 'metadata' => ['used' => false, 'sources' => []]];
        $response = $service->ask($payload['message'], $this->organization->id, $this->actor, null, $payload);
        $this->assertSame('completed', $response['status']);
        $this->assertSame(3020, AICreditProviderUsage::query()->sole()->metadata['input_tokens']);
        $this->assertSame(7, AICreditProviderUsage::query()->sole()->metadata['output_tokens']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $userMessage = Message::query()->where('role', 'user')->sole();
        $this->assertSame('Привет', $userMessage->content);
        $this->assertSame($image->public_id, $userMessage->metadata['attachments'][0]['id']);
        $this->assertStringNotContainsString('base64', json_encode($userMessage->getAttributes(), JSON_THROW_ON_ERROR));
        $followup = $this->quote(['message' => 'Привет', 'profile' => 'normal'], conversationId: $response['conversation_id']);
        $this->assertSame('completed', $service->ask('Привет', $this->organization->id, $this->actor, $response['conversation_id'], $followup)['status']);
        $this->assertSame(2, AICreditProviderUsage::query()->count());
    }

    public function test_quoted_images_cannot_be_swapped_and_failed_begin_does_not_bind_or_charge(): void
    {
        $first = $this->imageFixture();
        $second = $this->imageFixture();
        $payload = $this->quote(['profile' => 'normal', 'attachment_ids' => [$first->public_id]]);
        $changed = array_replace($payload, ['attachment_ids' => [$second->public_id]]);
        $this->assertOperationThrows(DomainException::class, fn () => $this->lifecycle->start($this->organization, $this->actor, null, $changed));
        $this->assertNull($second->fresh()->request_id);
        $this->assertSame(0, AICreditReservation::query()->count());
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
    }

    public function test_queued_image_checksum_change_fails_worker_and_releases_all_credits(): void
    {
        $image = $this->imageFixture();
        $payload = $this->quote(['profile' => 'normal', 'attachment_ids' => [$image->public_id]]);
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $payload, 'lk')['request'];
        $this->assertSame([$image->public_id], $request->payload['attachment_ids']);
        $this->assertArrayNotHasKey('attachment_manifest', $request->payload);
        $this->assertStringNotContainsString('base64', json_encode($request->payload, JSON_THROW_ON_ERROR));
        $image->forceFill(['checksum' => str_repeat('f', 64)])->save();
        $provider = $this->createMock(LLMProviderInterface::class);
        $provider->expects($this->never())->method('chat');
        app()->instance(AIAssistantService::class, $this->providerService($provider));
        $this->assertOperationThrows(AuthorizationException::class, fn () => (new ExecuteAssistantChatJob($request->id))->handle($this->lifecycle, app(AssistantDataAccessPolicy::class)));
        $this->assertSame('failed', $request->fresh()->status);
        $this->assertSame(0, AICreditProviderUsage::query()->count());
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
    }

    private function imageFixture(): ChatAttachment
    {
        $image = imagecreatetruecolor(16, 16);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        $id = (string) Str::uuid();
        $row = ChatAttachment::query()->create(['public_id' => $id, 'organization_id' => $this->organization->id, 'user_id' => $this->actor->id, 'name' => 'photo.png', 'mime' => 'image/png', 'width' => 16, 'height' => 16, 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'storage_path' => 'org-'.$this->organization->id.'/ai-assistant/chat-images/'.$id.'.png']);
        $files = $this->mock(FileService::class);
        $files->shouldReceive('readCurrentBounded')->andReturnUsing(static function () use ($bytes) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $bytes);
            rewind($stream);
            return $stream;
        });
        $this->app->instance(AssistantChatAttachmentService::class, new AssistantChatAttachmentService($files, $this->conversations, app(AIPermissionChecker::class)));
        return $row;
    }

    private function assertSameJsonObject(array $expected, array $actual): void
    {
        $this->assertSame($this->sortJsonObjectKeys($expected), $this->sortJsonObjectKeys($actual));
    }

    private function sortJsonObjectKeys(array $value): array
    {
        if (!array_is_list($value)) { ksort($value); }
        foreach ($value as $key => $item) {
            if (is_array($item)) { $value[$key] = $this->sortJsonObjectKeys($item); }
        }
        return $value;
    }

    private function assertOperationThrows(string $class, callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($class, $exception);

            return;
        }
        $this->fail('Expected '.$class);
    }

    private function tables(): array
    {
        return ['ai_chat_attachments', 'ai_assistant_requests', 'ai_messages', 'ai_conversation_summaries', 'ai_conversation_participants', 'ai_conversations', 'ai_credit_provider_usages', 'ai_credit_ledger_entries', 'ai_credit_reservation_allocations', 'ai_credit_reservations', 'ai_credit_quotes', 'ai_credit_lots', 'ai_credit_wallets', 'organization_user', 'commercial_orders', 'users', 'organizations'];
    }

    private function providerService(LLMProviderInterface $provider, ?AIToolRegistry $registry = null, ?\App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator $orchestrator = null, ?\App\BusinessModules\Features\AIAssistant\Services\UsageTracker $usageOverride = null): LifecycleProviderAIAssistantService
    {
        $registry ??= new AIToolRegistry;
        $permissions = app(AIPermissionChecker::class);
        $usage = $usageOverride ?? $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\UsageTracker::class);
        if ($usageOverride === null) {
            $usage->method('canMakeRequest')->willReturn(true);
            $usage->method('getUsageStats')->willReturn([]);
        }
        if ($orchestrator === null) {
            $orchestrator = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator::class);
            $orchestrator->method('plan')->willReturn(['request' => ['context' => []], 'task_type' => 'summary', 'access_context_public' => [], 'capability' => []]);
        }

        return new LifecycleProviderAIAssistantService(
            $provider, $this->conversations,
            $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\ContextBuilder::class),
            $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer::class),
            $usage,
            $this->createMock(\App\Services\Logging\LoggingService::class),
            $registry, $permissions,
            $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver::class),
            $orchestrator,
            new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore,
            new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner(new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog, new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver),
            new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor($registry, $permissions, new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer),
            new \App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier,
            requestLifecycle: $this->lifecycle,
            dataAccess: app(AssistantDataAccessPolicy::class),
            tokenBudget: new TokenBudgetService(new TokenCounter(new class {
                public function encode(string $text): array { return array_fill(0, mb_strlen($text), 1); }
            })),
        );
    }
}

final class LifecycleProviderAIAssistantService extends AIAssistantService
{
    public bool $probeProviderDuringAsk = false;
    public bool $failOnRagBuild = false;
    public ?array $ragContextOverride = null;

    protected function buildRagContext(string $query, int $organizationId, User $user, array $taskPlan, array $requestPayload): array
    {
        if ($this->failOnRagBuild) {
            throw new \RuntimeException('greeting_must_not_build_rag_context');
        }
        if ($this->ragContextOverride !== null) {
            return $this->ragContextOverride;
        }
        return parent::buildRagContext($query, $organizationId, $user, $taskPlan, $requestPayload);
    }

    protected function handleAgentFlow(string $query, int $organizationId, User $user, Conversation $conversation, array $taskPlan): ?array
    {
        return $this->probeProviderDuringAsk
            ? $this->requestAssistantResponse([['role' => 'user', 'content' => $query]], [], $organizationId, $user)
            : parent::handleAgentFlow($query, $organizationId, $user, $conversation, $taskPlan);
    }

    public function invokeProvider(AssistantRequest $request, User $actor, array $messages): array
    {
        (new \ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($this, $request);
        (new \ReflectionProperty(AIAssistantService::class, 'activeActor'))->setValue($this, $actor);
        (new \ReflectionProperty(AIAssistantService::class, 'activeProfile'))->setValue($this, $request->profile);

        return $this->requestAssistantResponse($messages, [], $request->organization_id, $actor);
    }
}
