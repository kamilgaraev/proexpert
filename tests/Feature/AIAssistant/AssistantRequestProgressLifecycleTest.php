<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditProviderUsage;
use App\Models\Credits\AICreditReservation;
use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AssistantRequestProgressLifecycleTest extends TestCase
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

    public function test_completed_response_preserves_progress_without_polling_between_steps(): void
    {
        $payload = $this->quote();
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $payload, 'admin')['request'];
        $this->assertSame([], $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'admin')['progress']);
        $this->lifecycle->progress($request, $this->actor, 'estimates', 'started');
        $this->lifecycle->progress($request, $this->actor, 'estimates', 'completed');
        $this->lifecycle->progress($request, $this->actor, 'warehouse', 'started');
        $this->lifecycle->progress($request, $this->actor, 'warehouse', 'completed');
        $this->assertSame(0, AICreditReservation::query()->findOrFail($request->reservation_id)->consumed_minor);
        $this->assertSame(0, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $response = $this->lifecycle->complete($request, $this->actor, ['message' => ['content' => 'Проверенный ответ']]);
        $status = $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'admin');
        $expected = [
            ['id' => 1, 'code' => 'estimates', 'state' => 'started'],
            ['id' => 2, 'code' => 'estimates', 'state' => 'completed'],
            ['id' => 3, 'code' => 'warehouse', 'state' => 'started'],
            ['id' => 4, 'code' => 'warehouse', 'state' => 'completed'],
        ];
        $this->assertSame($expected, $response['progress']);
        $this->assertSame($expected, $status['progress']);
        $this->assertSame($expected, $status['response']['progress']);
        $this->assertSame($request->request_id, $status['request_id']);
        $this->assertSame(0, $status['calls_used']);
        $this->assertSame(0, AICreditProviderUsage::query()->count());
        $this->assertSame(50, $response['credit_usage']['charged_minor']);
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $this->expectException(AssistantRequestCancelled::class);
        $this->lifecycle->progress($request, $this->actor, 'warehouse', 'started');
    }

    public function test_cancellation_blocks_a_late_completed_event(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $this->lifecycle->claimQueued($request->id);
        $this->lifecycle->progress($request, $this->actor, 'warehouse', 'started');
        $status = $this->lifecycle->cancel($request->request_id, $this->actor, $this->organization->id, 'admin');
        $this->assertSame('cancel_requested', $status['status']);
        try {
            $this->lifecycle->progress($request, $this->actor, 'warehouse', 'completed');
            $this->fail('Cancelled request accepted a progress write');
        } catch (AssistantRequestCancelled) {
            $this->assertSame([['id' => 1, 'code' => 'warehouse', 'state' => 'started']], $request->refresh()->response['progress']);
        }
    }

    public function test_failed_request_preserves_started_event_and_never_claims_completion(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $this->lifecycle->progress($request, $this->actor, 'estimates', 'started');
        $this->lifecycle->fail($request);
        $status = $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'admin');
        $this->assertSame('failed', $status['status']);
        $this->assertSame([['id' => 1, 'code' => 'estimates', 'state' => 'started']], $status['progress']);
        $this->assertArrayNotHasKey('response', $status);
        $this->expectException(AssistantRequestCancelled::class);
        $this->lifecycle->progress($request, $this->actor, 'estimates', 'completed');
    }

    public function test_revoked_access_blocks_progress_writes_and_status_reads(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $this->lifecycle->progress($request, $this->actor, 'warehouse', 'started');
        $this->assistantEnabled = false;
        try {
            $this->lifecycle->progress($request, $this->actor, 'warehouse', 'completed');
            $this->fail('Revoked actor accepted a progress write');
        } catch (AuthorizationException) {
            $this->assertCount(1, $request->refresh()->response['progress']);
        }
        $this->expectException(AuthorizationException::class);
        $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'admin');
    }

    public function test_progress_respects_request_owner_and_surface(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $other = $this->member('Другой участник');
        try {
            $this->lifecycle->progress($request, $other, 'estimates', 'completed');
            $this->fail('Another actor accepted a progress write');
        } catch (AuthorizationException) {
            $this->assertNull($request->refresh()->response);
        }
        $this->lifecycle->progress($request, $this->actor, 'estimates', 'started');
        $this->expectException(AuthorizationException::class);
        $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'lk');
    }

    public function test_empty_rag_search_finishes_without_claiming_domain_data_was_read(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $builder = $this->createMock(\Illuminate\Database\Eloquent\Builder::class);
        $builder->method('pluck')->willReturn(collect());
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->method('queryAccessibleProjects')->willReturn($builder);
        $service = $this->ragService($request, $projectAccess);

        $context = (new \ReflectionMethod(AIAssistantService::class, 'buildRagContext'))
            ->invoke($service, 'Найди сведения о смете', $this->organization->id, $this->actor, [], ['context' => ['project_id' => 999]]);

        $this->assertFalse($context['metadata']['used']);
        $this->assertSame([], $context['metadata']['sources']);
        $this->assertSame([
            ['id' => 1, 'code' => 'rag_search', 'state' => 'started'],
            ['id' => 2, 'code' => 'rag_search', 'state' => 'completed'],
        ], $request->refresh()->response['progress']);
        $this->assertSame(0, AICreditProviderUsage::query()->count());
    }

    public function test_failed_rag_search_never_claims_completion(): void
    {
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, null, $this->quote(), 'admin')['request'];
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->method('queryAccessibleProjects')->willThrowException(new \RuntimeException('Search failed'));
        $service = $this->ragService($request, $projectAccess);

        $context = (new \ReflectionMethod(AIAssistantService::class, 'buildRagContext'))
            ->invoke($service, 'Найди сведения о смете', $this->organization->id, $this->actor, [], []);

        $this->assertFalse($context['metadata']['used']);
        $this->assertSame([['id' => 1, 'code' => 'rag_search', 'state' => 'started']], $request->refresh()->response['progress']);
    }

    private function ragService(AssistantRequest $request, UserProjectAccessService $projectAccess): AIAssistantService
    {
        if (!Schema::hasTable('ai_rag_sources')) {
            Schema::create('ai_rag_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->string('source_type');
                $table->string('entity_type');
                $table->jsonb('metadata')->nullable();
            });
        }
        $embedding = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface::class);
        $embedding->expects($this->never())->method('embed');
        $retriever = new RagRetriever($embedding, $projectAccess, app(AssistantDataAccessPolicy::class));
        $service = (new \ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        foreach ([
            'activeRequest' => $request, 'activeActor' => $this->actor, 'requestLifecycle' => $this->lifecycle,
            'ragRetriever' => $retriever,
            'ragPromptContextBuilder' => new \App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder,
            'logging' => $this->createMock(\App\Services\Logging\LoggingService::class),
        ] as $property => $value) {
            (new \ReflectionProperty(AIAssistantService::class, $property))->setValue($service, $value);
        }
        return $service;
    }

    private function member(string $name): User
    {
        $user = User::withoutEvents(fn () => User::query()->create(['name' => $name, 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true, 'current_organization_id' => $this->organization->id]));
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $user->id, 'is_active' => true, 'is_owner' => true]);

        return $user;
    }

    private function quote(array $overrides = [], ?User $actor = null, ?int $conversationId = null): array
    {
        $payload = array_replace(['request_id' => (string) Str::uuid(), 'message' => 'Проверь текущие данные', 'profile' => 'short', 'allow_actions' => false, 'conversation_id' => $conversationId, 'context' => []], $overrides);
        $quote = $this->credits->quote($this->organization, $actor ?? $this->actor, $payload);

        return $payload + ['quote_id' => $quote['quote_id']];
    }

    private function tables(): array
    {
        return ['ai_chat_attachments', 'ai_assistant_requests', 'ai_messages', 'ai_conversation_summaries', 'ai_conversation_participants', 'ai_conversations', 'ai_credit_provider_usages', 'ai_credit_ledger_entries', 'ai_credit_reservation_allocations', 'ai_credit_reservations', 'ai_credit_quotes', 'ai_credit_lots', 'ai_credit_wallets', 'organization_user', 'commercial_orders', 'users', 'organizations'];
    }
}
