<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialAnswerService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

final class AssistantApiContractTest extends TestCase
{
    private const PREFIXES = ['/api/v1/ai-assistant', '/api/v1/admin/ai-assistant', '/api/v1/mobile/ai-assistant'];
    private Organization $organization;
    private User $actor;
    private bool $moduleEnabled = true;
    private bool $permissionGranted = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::withoutEvents(fn () => Organization::factory()->create(['name' => 'Контракт помощника', 'is_active' => true, 'is_verified' => true]));
        $this->actor = $this->member('Автор');
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('canCurrent')->andReturnUsing(fn (): bool => $this->permissionGranted);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $modules = $this->mock(OrganizationEntitlementService::class);
        $modules->shouldReceive('getEffectiveModules')->andReturnUsing(fn () => $this->moduleEnabled ? collect([new Module(['slug' => 'ai-assistant'])]) : collect());
        $this->app->instance(AssistantDataAccessPolicy::class, new AssistantDataAccessPolicy($authorization, $this->mock(UserProjectAccessService::class), $modules));
        $this->app->instance(TokenBudgetService::class, new TokenBudgetService(new TokenCounter(new class {
            public function encode(string $text): array { return array_fill(0, mb_strlen($text), 1); }
        })));
        config()->set('ai-assistant-credits.enforce', false);
        $context = new AssistantApiFixtureContext($this->actor, $this->organization->id);
        $this->app->instance(AssistantApiFixtureContext::class, $context);
        foreach ($this->app['router']->getRoutes() as $route) {
            if (preg_match('~^api/v1/(?:admin/|mobile/)?ai-assistant(?:/|$)~', $route->uri()) === 1) {
                $route->withoutMiddleware($route->gatherMiddleware());
                $route->middleware(AssistantApiFixtureAuthentication::class);
                $route->computedMiddleware = null;
            }
        }
    }

    public function test_provider_builds_actual_service_catalog_and_registered_read_tools(): void
    {
        $this->assertInstanceOf(AIAssistantServiceProvider::class, $this->app->getProvider(AIAssistantServiceProvider::class));
        $this->assertInstanceOf(AIAssistantService::class, $this->app->make(AIAssistantService::class));
        $registry = $this->app->make(AIToolRegistry::class);
        foreach (['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'resolve_estimate', 'get_estimate_positions', 'get_estimate_financial_snapshot'] as $name) {
            $this->assertNotNull($registry->getTool($name), $name);
        }
        $catalog = $this->app->make(AssistantDomainCatalog::class);
        foreach (['projects', 'estimates', 'contracts', 'finance', 'procurement', 'schedule'] as $domain) {
            $this->assertNotNull($catalog->definition($domain), $domain);
        }
    }

    public function test_container_injects_all_optional_safety_and_context_dependencies(): void
    {
        $service = $this->app->make(AIAssistantService::class);
        foreach ([
            'financialAnswers' => AssistantFinancialAnswerService::class,
            'financialClaims' => AssistantFinancialClaimVerifier::class,
            'memoryService' => AssistantMemoryService::class,
            'requestLifecycle' => AssistantRequestLifecycle::class,
            'dataAccess' => AssistantDataAccessPolicy::class,
            'structuredFacts' => AssistantStructuredFactVerifier::class,
            'legacyLiveEvidence' => \App\BusinessModules\Features\AIAssistant\Services\AssistantLegacyLiveEvidenceAdapter::class,
            'ragRetriever' => RagRetriever::class,
            'ragPromptContextBuilder' => RagPromptContextBuilder::class,
            'toolEligibilityPolicy' => AssistantToolEligibilityPolicy::class,
            'tokenBudget' => TokenBudgetService::class,
        ] as $property => $expectedClass) {
            $dependency = (new ReflectionProperty(AIAssistantService::class, $property))->getValue($service);
            $this->assertInstanceOf($expectedClass, $dependency, 'Container silently dropped '.$property);
        }
    }

    public function test_tool_registry_rebinds_scoped_policy_between_worker_jobs(): void
    {
        $firstPolicy = app(AssistantDataAccessPolicy::class);
        $firstRegistry = app(AIToolRegistry::class);
        $firstTool = $firstRegistry->getTool('assistant_domain_discover_capabilities');
        $this->assertSame($firstPolicy, (new ReflectionProperty($firstTool, 'access'))->getValue($firstTool));

        app()->forgetScopedInstances();

        $secondPolicy = app(AssistantDataAccessPolicy::class);
        $secondRegistry = app(AIToolRegistry::class);
        $secondTool = $secondRegistry->getTool('assistant_domain_discover_capabilities');
        $this->assertNotSame($firstRegistry, $secondRegistry);
        $this->assertNotSame($firstPolicy, $secondPolicy);
        $this->assertSame($secondPolicy, (new ReflectionProperty($secondTool, 'access'))->getValue($secondTool));
    }

    public function test_all_prefixes_preserve_full_4000_character_quote_and_chat_payload(): void
    {
        Queue::fake([ExecuteAssistantChatJob::class]);
        $service = $this->mock(AIAssistantService::class);
        $service->shouldNotReceive('ask');
        $message = str_repeat('я', 3994).' бетон';
        $this->assertSame(4000, mb_strlen($message));
        foreach (self::PREFIXES as $prefix) {
            $payload = $this->payload($message) + ['organization_id' => 999999];
            $quote = $this->postJson($prefix.'/credits/quote', $payload)->assertOk()->assertJsonPath('success', true)
                ->assertJsonStructure(['success', 'message', 'data' => ['quote_id', 'profile', 'metadata' => ['processing_deadline_seconds']]])
                ->assertJsonPath('data.metadata.processing_deadline_seconds', 30);
            $chat = $this->postJson($prefix.'/chat', $payload + ['quote_id' => $quote->json('data.quote_id'), 'async' => false]);
            $chat->assertStatus(202, $chat->getContent())->assertJsonPath('success', true)->assertJsonPath('data.request_id', $payload['request_id']);
            $stored = DB::table('ai_assistant_requests')->where('request_id', $payload['request_id'])->first();
            $this->assertNotNull($stored);
            $storedPayload = json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($message, $storedPayload['message']);
            $this->assertArrayNotHasKey('organization_id', $storedPayload);
            $this->postJson($prefix.'/credits/quote', $this->payload($message.'я'))->assertUnprocessable()->assertJsonValidationErrors('message');
            $this->postJson($prefix.'/chat', $this->payload($message.'я') + ['quote_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('message');
        }
        Queue::assertPushed(ExecuteAssistantChatJob::class, 3);
    }

    public function test_credit_quote_reports_bounded_processing_deadline_for_each_profile_and_prefix(): void
    {
        foreach (['short' => 30, 'normal' => 60, 'detailed' => 180] as $profile => $deadline) {
            foreach (self::PREFIXES as $prefix) {
                $payload = $this->payload();
                $payload['profile'] = $profile;
                $this->postJson($prefix.'/credits/quote', $payload)->assertOk()
                    ->assertJsonPath('data.profile', $profile)
                    ->assertJsonPath('data.metadata.processing_deadline_seconds', $deadline);
            }
        }
    }

    public function test_chat_always_queues_all_prefixes_and_replays_duplicate_submit_without_resolving_provider_or_tools(): void
    {
        $queue = Queue::fake([ExecuteAssistantChatJob::class]);
        $conversation = app(ConversationManager::class)->createConversation($this->organization->id, $this->actor, 'История для API');
        $resolutions = ['provider' => 0, 'tools' => 0];
        $this->app->bind(AIAssistantService::class, function () use (&$resolutions): never {
            $resolutions['provider']++;
            throw new \LogicException('Provider service must not be resolved by the HTTP chat request.');
        });
        $this->app->bind(AIToolRegistry::class, function () use (&$resolutions): never {
            $resolutions['tools']++;
            throw new \LogicException('Tool registry must not be resolved by chat, status, or history requests.');
        });

        foreach (self::PREFIXES as $prefix) {
            foreach ([null, false, true] as $async) {
                $payload = $this->payload();
                $quote = $this->postJson($prefix.'/credits/quote', $payload)->assertOk();
                $body = $payload + ['quote_id' => $quote->json('data.quote_id')];
                if ($async !== null) {
                    $body['async'] = $async;
                }
                $created = $this->postJson($prefix.'/chat', $body)->assertStatus(202)->assertJsonPath('success', true)
                    ->assertJsonPath('data.request_id', $payload['request_id'])->assertJsonPath('data.status', 'running')
                    ->assertJsonPath('data.stage', 'queued');
                $this->assertNull($created->json('data.conversation_id'));
                $this->postJson($prefix.'/chat', $body)->assertStatus(202)->assertJsonPath('data.request_id', $payload['request_id']);
                $this->getJson($prefix.'/requests/'.$payload['request_id'])->assertOk()->assertJsonPath('data.status', 'running');
                $this->getJson($prefix.'/conversations/'.$conversation->id.'/history')->assertOk()->assertJsonPath('success', true);
            }
        }
        Queue::assertPushed(ExecuteAssistantChatJob::class, 18);
        $this->assertCount(9, $queue->pushed(ExecuteAssistantChatJob::class)->pluck('assistantRequestId')->unique());
        $this->assertSame(9, \App\BusinessModules\Features\AIAssistant\Models\AssistantRequest::query()
            ->where('organization_id', $this->organization->id)->count());
        $this->assertSame(['provider' => 0, 'tools' => 0], $resolutions);
    }

    public function test_chat_and_status_run_one_authoritative_assistant_permission_gate(): void
    {
        Queue::fake([ExecuteAssistantChatJob::class]);
        $checker = new CountingAssistantPermissionChecker;
        $this->app->instance(AIPermissionChecker::class, $checker);
        $scopedRequestId = null;

        foreach (self::PREFIXES as $index => $prefix) {
            $payload = $this->payload();
            $quote = $this->postJson($prefix.'/credits/quote', $payload)->assertOk();
            $checker->resetCalls();

            $this->postJson($prefix.'/chat', $payload + ['quote_id' => $quote->json('data.quote_id')])->assertStatus(202);
            $this->assertSame(1, $checker->calls, 'Chat submit must rely on one authoritative lifecycle permission gate.');
            $scopedRequestId ??= $payload['request_id'];

            $checker->resetCalls();
            $this->getJson($prefix.'/requests/'.$payload['request_id'])->assertOk();
            $this->assertSame(1, $checker->calls, 'Status must rely on one authoritative lifecycle permission gate.');

            $otherPrefix = self::PREFIXES[($index + 1) % count(self::PREFIXES)];
            $this->getJson($otherPrefix.'/requests/'.$payload['request_id'])->assertForbidden();
        }

        $context = $this->app->make(AssistantApiFixtureContext::class);
        $context->organizationId++;
        $this->getJson(self::PREFIXES[0].'/requests/'.$scopedRequestId)->assertForbidden();
        $context->organizationId = $this->organization->id;

        $this->moduleEnabled = false;
        $this->getJson(self::PREFIXES[0].'/requests/'.$scopedRequestId)->assertForbidden();
        $this->moduleEnabled = true;
        $this->permissionGranted = false;
        $this->getJson(self::PREFIXES[0].'/requests/'.$scopedRequestId)->assertForbidden();
        $this->permissionGranted = true;
        DB::table('organization_user')->where('user_id', $this->actor->id)->update(['is_active' => false]);
        $this->getJson(self::PREFIXES[0].'/requests/'.$scopedRequestId)->assertForbidden();
    }

    public function test_guarded_current_organization_module_and_permission_for_quote_and_chat(): void
    {
        $service = $this->mock(AIAssistantService::class);
        $service->shouldNotReceive('ask');
        foreach (['organization', 'module', 'permission', 'membership'] as $denial) {
            $context = $this->app->make(AssistantApiFixtureContext::class);
            $context->organizationId = $denial === 'organization' ? $this->organization->id + 100 : $this->organization->id;
            $this->moduleEnabled = $denial !== 'module';
            $this->permissionGranted = $denial !== 'permission';
            DB::table('organization_user')->where('user_id', $this->actor->id)->update(['is_active' => $denial !== 'membership']);
            foreach (self::PREFIXES as $prefix) {
                $this->postJson($prefix.'/credits/quote', $this->payload())->assertForbidden();
                $this->postJson($prefix.'/chat', $this->payload() + ['quote_id' => (string) Str::uuid()])->assertForbidden()->assertJsonPath('success', false);
            }
        }
    }

    public function test_chat_returns_queued_response_on_all_prefixes_before_worker_output_exists(): void
    {
        Queue::fake([ExecuteAssistantChatJob::class]);
        $service = $this->mock(AIAssistantService::class);
        $service->shouldNotReceive('ask');
        foreach (self::PREFIXES as $prefix) {
            $payload = $this->payload();
            $quote = $this->postJson($prefix.'/credits/quote', $payload)->assertOk();
            $payload['quote_id'] = $quote->json('data.quote_id');
            $response = $this->postJson($prefix.'/chat', $payload);
            $response->assertStatus(202)->assertJsonPath('success', true)
                ->assertJsonPath('data.request_id', $payload['request_id'])->assertJsonPath('data.stage', 'queued');
        }
        Queue::assertPushed(ExecuteAssistantChatJob::class, 3);
    }

    public function test_conversation_and_usage_gates_are_bounded_and_recheck_current_access(): void
    {
        $checker = new CountingAssistantPermissionChecker;
        $this->app->instance(AIPermissionChecker::class, $checker);
        $context = $this->app->make(AssistantApiFixtureContext::class);
        foreach (self::PREFIXES as $prefix) {
            $checker->resetCalls();
            $this->postJson($prefix.'/conversations', ['title' => 'Быстрый доступ'])->assertCreated()->assertJsonPath('success', true);
            $this->assertSame(2, $checker->calls);
            $checker->resetCalls();
            $this->getJson($prefix.'/conversations?per_page=1&page=1')->assertOk()->assertJsonPath('meta.per_page', 1);
            $this->assertSame(2, $checker->calls);
            $checker->resetCalls();
            $this->getJson($prefix.'/usage')->assertOk()->assertJsonPath('success', true);
            $this->assertSame(1, $checker->calls);
        }
        foreach (['organization', 'module', 'permission', 'membership'] as $denial) {
            $context->organizationId = $denial === 'organization' ? $this->organization->id + 100 : $this->organization->id;
            $this->moduleEnabled = $denial !== 'module';
            $this->permissionGranted = $denial !== 'permission';
            DB::table('organization_user')->where('user_id', $this->actor->id)->update(['is_active' => $denial !== 'membership']);
            foreach (self::PREFIXES as $prefix) {
                $this->getJson($prefix.'/conversations')->assertForbidden()->assertJsonPath('data', null);
                $this->postJson($prefix.'/conversations', ['title' => 'Запрещено'])->assertForbidden()->assertJsonPath('data', null);
                $this->getJson($prefix.'/usage')->assertForbidden()->assertJsonPath('data', null);
            }
        }
    }

    public function test_conversation_pages_resolve_current_visibility_once_and_recheck_access(): void
    {
        $manager = $this->app->make(ConversationManager::class);
        $conversation = $manager->createConversation($this->organization->id, $this->actor, 'История');
        $manager->addMessage($conversation, 'user', 'Вопрос');
        foreach (self::PREFIXES as $prefix) {
            foreach (['', '/history'] as $suffix) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                try {
                    $this->getJson($prefix.'/conversations/'.$conversation->id.$suffix)->assertOk()->assertJsonPath('meta.total', 1);
                    $conversationReads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "ai_conversations"'));
                    $this->assertCount(1, $conversationReads);
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
            }
        }
        $context = $this->app->make(AssistantApiFixtureContext::class);
        foreach (['organization', 'module', 'permission', 'membership'] as $denial) {
            $context->organizationId = $denial === 'organization' ? $this->organization->id + 100 : $this->organization->id;
            $this->moduleEnabled = $denial !== 'module';
            $this->permissionGranted = $denial !== 'permission';
            DB::table('organization_user')->where('user_id', $this->actor->id)->update(['is_active' => $denial !== 'membership']);
            foreach (self::PREFIXES as $prefix) {
                foreach (['', '/history'] as $suffix) {
                    $this->getJson($prefix.'/conversations/'.$conversation->id.$suffix)->assertForbidden()->assertJsonPath('data', null);
                }
            }
        }
    }

    public function test_numeric_conversation_ids_private_visibility_and_pagination_envelopes(): void
    {
        $other = $this->member('Другой');
        $manager = $this->app->make(ConversationManager::class);
        $private = $manager->createConversation($this->organization->id, $other, 'Чужая беседа');
        $policy = $this->app->make(AssistantDataAccessPolicy::class);
        $this->assertTrue($policy->canReadDomain($this->actor, $this->organization->id, 'assistant'));
        $this->assertTrue($policy->canReadDomain($other, $this->organization->id, 'assistant'));
        $this->assertNotNull($manager->findAccessibleConversation($private->id, $other, $this->organization->id));
        $this->assertNull($manager->findAccessibleConversation($private->id, $this->actor, $this->organization->id));
        foreach (self::PREFIXES as $prefix) {
            $created = $this->postJson($prefix.'/conversations', ['title' => 'Моя беседа'])->assertCreated()->assertJsonPath('success', true);
            $id = $created->json('data.id');
            $this->assertIsInt($id);
            $this->getJson($prefix.'/conversations/'.$id)->assertOk()->assertJsonPath('data.conversation.id', $id)->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total']]);
            $list = $this->getJson($prefix.'/conversations?per_page=1&page=1')->assertOk()->assertJsonPath('success', true)->assertJsonPath('meta.per_page', 1);
            $this->assertCount(1, $list->json('data'));
            $this->assertNotContains($private->id, array_column($list->json('data'), 'id'));
            $denied = $this->getJson($prefix.'/conversations/'.$private->id)->assertForbidden()->assertJsonPath('success', false)->assertJsonPath('data', null);
            $this->assertStringNotContainsString('Чужая беседа', $denied->getContent());
            $missing = $this->getJson($prefix.'/conversations/'.($private->id + 1000000))->assertForbidden()->assertJsonPath('data', null);
            $this->assertSame($denied->json(), $missing->json());
            $this->getJson($prefix.'/conversations/'.Str::uuid())->assertNotFound();
            $this->getJson($prefix.'/conversations?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['page', 'per_page']);
        }
    }

    public function test_action_and_request_identifiers_reject_arbitrary_client_parameters(): void
    {
        foreach (self::PREFIXES as $prefix) {
            $this->postJson($prefix.'/chat', $this->payload() + ['quote_id' => 'invalid', 'conversation_id' => 'conversation-uuid'])->assertUnprocessable()->assertJsonValidationErrors(['quote_id', 'conversation_id']);
            $this->postJson($prefix.'/actions/execute', ['conversation_id' => 1, 'action' => ['id' => (string) Str::uuid(), 'preview_token' => str_repeat('a', 64), 'confirmed' => true, 'parameters' => ['organization_id' => 999]]])->assertUnprocessable()->assertJsonValidationErrors('action');
            $this->getJson($prefix.'/requests/not-a-uuid')->assertNotFound();
        }
    }

    private function payload(string $message = 'Проверь текущие данные'): array
    {
        return ['message' => $message, 'request_id' => (string) Str::uuid(), 'profile' => 'short', 'allow_actions' => false, 'context' => ['source_module' => 'ai-assistant', 'source_route' => '/ai-assistant']];
    }

    private function member(string $name): User
    {
        $user = User::withoutEvents(fn () => User::query()->create(['name' => $name, 'email' => Str::uuid().'@example.test', 'password' => 'password', 'is_active' => true, 'current_organization_id' => $this->organization->id]));
        $user->organizations()->attach($this->organization->id, ['is_active' => true, 'is_owner' => true]);
        return $user;
    }

}

final class AssistantApiFixtureContext
{
    public function __construct(public User $actor, public int $organizationId) {}
}

final class AssistantApiFixtureAuthentication
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(AssistantApiFixtureContext::class);
        $request->setUserResolver(fn () => $context->actor);
        $request->attributes->set('current_organization_id', $context->organizationId);
        return $next($request);
    }
}

final class CountingAssistantPermissionChecker extends AIPermissionChecker
{
    public int $calls = 0;

    public function canUseAssistant(User $user, int $organizationId, bool $fresh = true): bool
    {
        $this->calls++;

        return parent::canUseAssistant($user, $organizationId, $fresh);
    }

    public function resetCalls(): void
    {
        $this->calls = 0;
    }
}
