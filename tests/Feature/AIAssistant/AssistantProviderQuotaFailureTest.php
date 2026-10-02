<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditReservation;
use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Logging\LoggingService;
use App\Services\Project\UserProjectAccessService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use OpenAI\Exceptions\RateLimitException;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AssistantProviderQuotaFailureTest extends TestCase
{
    private ?string $connectionName = null;
    private ?array $originalConnectionConfiguration = null;
    private Organization $organization;
    private User $actor;
    private AICreditService $credits;
    private AssistantRequestLifecycle $lifecycle;
    private ConversationManager $conversations;
    private array $providerRequests = [];

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config('database.connections.'.$this->connectionName);
        config()->set('database.connections.'.$this->connectionName, IsolatedPostgresTestDatabase::configuration());
        DB::purge($this->connectionName);
        DB::connection($this->connectionName);
        foreach (['ai_assistant_requests', 'ai_messages', 'ai_conversation_summaries', 'ai_conversation_participants', 'ai_conversations', 'ai_credit_provider_usages', 'ai_credit_ledger_entries', 'ai_credit_reservation_allocations', 'ai_credit_reservations', 'ai_credit_quotes', 'ai_credit_lots', 'ai_credit_wallets', 'organization_user', 'commercial_orders', 'users', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('organizations', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->boolean('is_active')->default(true); $table->boolean('is_verified')->default(true); $table->timestamps(); $table->softDeletes(); });
        Schema::create('users', function (Blueprint $table): void { $table->id(); $table->string('name'); $table->string('email'); $table->string('password'); $table->boolean('is_active')->default(true); $table->unsignedBigInteger('current_organization_id')->nullable(); $table->timestamps(); $table->softDeletes(); });
        Schema::create('organization_user', function (Blueprint $table): void { $table->id(); $table->foreignId('organization_id'); $table->foreignId('user_id'); $table->boolean('is_active')->default(true); $table->boolean('is_owner')->default(false); $table->timestamps(); });
        Schema::create('commercial_orders', function (Blueprint $table): void { $table->id(); $table->string('kind')->default('purchase'); });
        Schema::create('ai_conversations', function (Blueprint $table): void { $table->id(); $table->foreignId('organization_id')->constrained(); $table->foreignId('user_id')->constrained(); $table->string('title')->nullable(); $table->jsonb('context')->nullable(); $table->timestamp('last_activity_at')->nullable(); $table->unsignedInteger('context_version')->default(1); $table->timestamps(); });
        Schema::create('ai_conversation_participants', function (Blueprint $table): void { $table->id(); $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete(); $table->foreignId('user_id')->constrained(); $table->foreignId('added_by_user_id')->constrained('users'); $table->string('role'); $table->timestamps(); $table->unique(['conversation_id', 'user_id']); });
        Schema::create('ai_messages', function (Blueprint $table): void { $table->id(); $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete(); $table->string('role'); $table->text('content'); $table->unsignedInteger('tokens_used')->default(0); $table->string('model')->nullable(); $table->jsonb('metadata')->nullable(); $table->timestamp('created_at')->useCurrent(); });
        Schema::create('ai_conversation_summaries', function (Blueprint $table): void { $table->id(); $table->foreignId('conversation_id')->unique()->constrained('ai_conversations')->cascadeOnDelete(); $table->text('summary')->nullable(); $table->jsonb('summary_segments')->nullable(); $table->jsonb('selected_entities')->nullable(); $table->jsonb('user_decisions')->nullable(); $table->jsonb('source_refs')->nullable(); $table->unsignedInteger('context_version')->default(1); $table->timestamps(); });
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        (require database_path('migrations/2026_09_29_000009_create_ai_assistant_requests_table.php'))->up();
        (require database_path('migrations/2026_09_29_000010_add_async_payload_to_ai_assistant_requests.php'))->up();
        $this->organization = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Luna quota failure']));
        $this->actor = User::withoutEvents(fn () => User::query()->create(['name' => 'Actor', 'email' => 'quota@example.test', 'password' => 'password', 'is_active' => true, 'current_organization_id' => $this->organization->id]));
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $this->actor->id, 'is_active' => true, 'is_owner' => true]);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = $this->mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'ai-assistant'])]));
        $policy = new AssistantDataAccessPolicy($authorization, $this->mock(UserProjectAccessService::class), $modules);
        $this->app->instance(AssistantDataAccessPolicy::class, $policy);
        $permissions = $this->mock(AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->andReturn(true);
        $this->conversations = new ConversationManager($policy);
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

    public function test_timeweb_quota_429_is_one_luna_request_without_model_fallback(): void
    {
        $provider = $this->timewebProvider();

        try {
            $provider->chat([['role' => 'user', 'content' => 'Проверь данные']], ['model' => 'openai/gpt-6-luna', 'profile' => 'short']);
            $this->fail('Expected the provider SDK rate limit exception.');
        } catch (RateLimitException $exception) {
            $errorBody = (string) $exception->response->getBody();
            $this->assertStringContainsString('credit_balance_exhausted', $errorBody);
        }

        $this->assertCount(1, $this->providerRequests);
        $this->assertSame('/v1/responses', $this->providerRequests[0]->getUri()->getPath());
        $body = json_decode((string) $this->providerRequests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('openai/gpt-6-luna', $body['model']);
    }

    public function test_sdk_quota_failure_fails_request_refunds_credits_and_deletes_pending_answer(): void
    {
        $provider = $this->timewebProvider();
        $conversation = $this->conversations->createConversation($this->organization->id, $this->actor, 'Quota failure');
        $payload = ['request_id' => (string) Str::uuid(), 'message' => 'Проверь данные', 'profile' => 'short', 'allow_actions' => false, 'conversation_id' => $conversation->id, 'context' => []];
        $quote = $this->credits->quote($this->organization, $this->actor, $payload);
        $payload['quote_id'] = $quote['quote_id'];
        $request = $this->lifecycle->startQueued($this->organization, $this->actor, $conversation->id, $payload, 'lk')['request'];
        $assistant = $this->createMock(AIAssistantService::class);
        $assistant->expects($this->once())->method('executeStartedRequest')->willReturnCallback(function (AssistantRequest $activeRequest, User $actor) use ($provider, $conversation): array {
            $this->lifecycle->beforeProviderCall($activeRequest, $actor, 8192, 1024);
            $this->conversations->addMessage($conversation, 'assistant', 'Временный ответ', 0, 'openai/gpt-6-luna', ['request_id' => $activeRequest->request_id, 'request_state' => 'pending', 'source_refs' => []]);

            return $provider->chat([['role' => 'user', 'content' => 'Проверь данные']], ['model' => 'openai/gpt-6-luna', 'profile' => 'short']);
        });
        $job = new ExecuteAssistantChatJob((int) $request->id);
        $this->assertSame(1, $job->tries);
        try {
            app()->instance(AIAssistantService::class, $assistant);
            $job->handle($this->lifecycle, app(AssistantDataAccessPolicy::class));
            $this->fail('Expected the provider SDK rate limit exception.');
        } catch (RateLimitException) {
            $request->refresh();
        }

        $request->refresh();
        $reservation = AICreditReservation::query()->findOrFail($request->reservation_id);
        $this->assertSame('failed', $request->status);
        $this->assertSame('request_failed', $request->error_code);
        $this->assertNull($request->response);
        $this->assertSame(0, Message::query()->where('role', 'assistant')->where('metadata->request_id', $request->request_id)->count());
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
        $this->assertSame(10000, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame('cancelled', $reservation->status);
        $this->assertSame(0, (int) $reservation->consumed_minor);
        $debit = AICreditLedgerEntry::query()->where('type', 'consume')->sole();
        $this->assertSame(0, $debit->amount_minor);
        $status = $this->lifecycle->status($request->request_id, $this->actor, $this->organization->id, 'lk');
        $this->assertSame(['request_id' => $request->request_id, 'conversation_id' => $conversation->id, 'status' => 'failed', 'stage' => 'failed', 'calls_used' => 1, 'max_calls' => $request->max_calls, 'progress' => [], 'error_code' => 'request_failed'], $status);
        $this->assertStringNotContainsString('credit_balance_exhausted', json_encode($status, JSON_THROW_ON_ERROR));
    }

    private function timewebProvider(): TimewebProvider
    {
        config()->set('ai-assistant.llm.timeweb.api_key', 'test-key');
        config()->set('ai-assistant.llm.timeweb.base_uri', 'https://api.timeweb.test/v1');
        config()->set('ai-assistant.llm.timeweb.model', 'openai/gpt-6-luna');
        $stack = HandlerStack::create(new MockHandler([new Response(429, ['Content-Type' => 'application/json'], json_encode(['error' => ['message' => 'insufficient_quota: credit_balance_exhausted']], JSON_THROW_ON_ERROR))]));
        $stack->push(Middleware::mapRequest(function ($request) {
            $this->providerRequests[] = $request;

            return $request;
        }));
        $provider = new TimewebProvider(app(LoggingService::class), new Client(['handler' => $stack]));

        return $provider;
    }
}
