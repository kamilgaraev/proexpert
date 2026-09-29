<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
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

    public function test_all_prefixes_preserve_full_4000_character_quote_and_chat_payload(): void
    {
        $message = str_repeat('я', 3994).' бетон';
        $this->assertSame(4000, mb_strlen($message));
        $service = $this->mock(AIAssistantService::class);
        $service->shouldReceive('ask')->times(3)->withArgs(fn (string $query, int $organizationId, User $actor, ?int $conversationId, array $payload): bool => $query === $message && $payload['message'] === $message && ! isset($payload['organization_id']) && $organizationId === $this->organization->id && $actor->id === $this->actor->id && $conversationId === null)->andReturn(['message' => ['content' => 'Точный ответ'], 'validation_status' => 'verified']);
        foreach (self::PREFIXES as $prefix) {
            $payload = $this->payload($message) + ['organization_id' => 999999];
            $quote = $this->postJson($prefix.'/credits/quote', $payload)->assertOk()->assertJsonPath('success', true)->assertJsonStructure(['success', 'message', 'data' => ['quote_id', 'profile']]);
            $this->postJson($prefix.'/chat', $payload + ['quote_id' => $quote->json('data.quote_id')])->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.message.content', 'Точный ответ');
            $this->postJson($prefix.'/credits/quote', $this->payload($message.'я'))->assertUnprocessable()->assertJsonValidationErrors('message');
            $this->postJson($prefix.'/chat', $this->payload($message.'я') + ['quote_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('message');
        }
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

    public function test_incomplete_output_returns_clear_409_without_partial_content_on_all_prefixes(): void
    {
        $service = $this->mock(AIAssistantService::class);
        $service->shouldReceive('ask')->times(3)->andThrow(new AssistantResponseIncomplete([
            'incomplete_reason' => 'max_output_tokens', 'content' => 'Секретный обрезанный ответ', 'input_tokens' => 80, 'output_tokens' => 128,
        ]));
        foreach (self::PREFIXES as $prefix) {
            $response = $this->postJson($prefix.'/chat', $this->payload() + ['quote_id' => (string) Str::uuid()]);
            $response->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('message', trans_message('ai_assistant.output_limit_exceeded'));
            $this->assertStringNotContainsString('Секретный', $response->getContent());
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
