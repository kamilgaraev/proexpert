<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\NavigationAssistantDomainTool;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\ReadAssistantDomainTool;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\SearchAssistantDomainTool;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainReadService;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Models\Module;
use App\Models\Project;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\PostgresBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class AssistantDiscoveryContextBudgetTest extends TestCase
{
    use UsesAssistantUnitTranslations { tearDown as private translationsTearDown; }

    private ?ConnectionResolverInterface $previousResolver = null;

    protected function tearDown(): void
    {
        $this->previousResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousResolver);
        $this->translationsTearDown();
    }

    public function test_short_request_sends_discovery_schema_and_acl_checked_hints_without_extra_model_call(): void
    {
        $service = $this->service(true);
        $plan = $this->plan();
        $tools = $service->definitions($plan);
        $this->assertSame(['assistant_domain_search', 'assistant_domain_read', 'assistant_domain_navigation', 'assistant_domain_discover_capabilities'], array_column(array_column($tools, 'function'), 'name'));
        $messages = $service->messages($plan);
        $references = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('registered_domain_capabilities', $references);
        $hints = $references['registered_domain_capabilities'];
        $this->assertSame('untrusted_reference_data', $references['kind']);
        $this->assertSame('checked_on_read', $hints['record_access']);
        $this->assertCount(1, $hints['capabilities']);
        $this->assertSame('project', $hints['capabilities'][0]['entity_type']);
        $this->assertCount(16, $hints['capabilities'][0]['fields']);
        $this->assertNotContains('secret_amount', $hints['capabilities'][0]['fields']);
        $this->assertSame(16, $hints['capabilities'][0]['next_field_offset']);
        $this->assertStringNotContainsString('FORGED_CAPABILITY', $messages[0]['content']);
        $this->assertStringNotContainsString('FORGED_CAPABILITY', json_encode($hints, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret_amount', json_encode($hints, JSON_THROW_ON_ERROR));
        $this->assertSame('Покажи проект', $messages[2]['content']);
        $counter = new TokenCounter;
        $this->assertLessThan(700, $counter->value($hints));
        $prepared = (new TokenBudgetService($counter))->prepare($messages, $tools, 'short');
        $this->assertSame(2, $prepared['max_calls']);
        $this->assertSame($messages, $prepared['messages']);
        $this->assertLessThanOrEqual(8192, $prepared['input_tokens']);
    }

    public function test_current_domain_denial_omits_metadata_hints_entirely(): void
    {
        $messages = $this->service(false)->messages($this->plan());
        $references = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('registered_domain_capabilities', $references);
        $this->assertSame('Покажи проект', $messages[2]['content']);
    }

    public function test_full_registered_catalog_preserves_short_context_and_four_wire_schemas(): void
    {
        $catalog = new AssistantDomainCatalog(AssistantDomainCatalog::defaults());
        $service = $this->service(true, $catalog);
        $plan = $this->plan();
        $messages = $service->messages($plan);
        $tools = $service->definitions($plan);
        $references = json_decode($messages[1]['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('registered_domain_capabilities', $references);
        $hints = $references['registered_domain_capabilities'];
        $this->assertSame('projects', $hints['capabilities'][0]['domain']);
        $this->assertSame('project', $hints['capabilities'][0]['entity_type']);
        $this->assertLessThanOrEqual(16, count($hints['capabilities'][0]['fields']));
        $counter = new TokenCounter;
        $this->assertLessThan(700, $counter->value($hints));
        $prepared = (new TokenBudgetService($counter))->prepare($messages, $tools, 'short');
        $this->assertCount(4, $prepared['tools']);
        $this->assertSame($messages, $prepared['messages']);
        $this->assertSame(2, $prepared['max_calls']);
        $this->assertLessThanOrEqual(8192, $prepared['input_tokens']);
    }

    private function plan(): array
    {
        return ['task_type' => 'summary', 'capability' => ['id' => 'projects', 'domain' => 'projects'],
            'request' => ['message' => 'Покажи проект', 'allow_actions' => false, 'context' => ['registered_domain_capabilities' => 'FORGED_CAPABILITY', 'entity_type' => 'secret_project', 'fields' => ['secret_amount']]],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve('Покажи проект', [])->toArray()];
    }

    private function service(bool $allowed, ?AssistantDomainCatalog $catalog = null): DiscoveryContextAssistantService
    {
        $fields = ['id', 'name', 'secret_amount', ...array_map(static fn (int $number): string => 'field_'.$number, range(1, 24))];
        $catalog ??= new AssistantDomainCatalog([new AssistantDomainDefinition('projects', 'projects', 'project', ['projects.view'], $fields, [], ['search', 'read', 'navigation'], '/projects', 'projects', ['project'], ['secret_amount' => ['finance.view']])]);
        $this->previousResolver = Model::getConnectionResolver();
        $connection = $this->getMockBuilder(PostgresConnection::class)->setConstructorArgs([static fn () => throw new \LogicException('Pure test must not connect to PostgreSQL')])->onlyMethods(['select'])->getMock();
        $connection->method('select')->willReturn([(object) ['exists' => true]]);
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn($connection);
        Model::setConnectionResolver($resolver);
        $schema = $this->getMockBuilder(PostgresBuilder::class)->setConstructorArgs([$connection])->onlyMethods(['getColumnListing'])->getMock();
        $schema->method('getColumnListing')->willReturnCallback(static fn (string $table): array => $table === 'projects' ? ['id', 'organization_id', 'name', 'deleted_at'] : []);
        app()->instance('db.schema', $schema);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(static fn (User $actor, string $permission): bool => $permission !== 'finance.view' && ($permission !== 'projects.view' || $allowed));
        $authorization->method('forCurrentChecks')->willReturnSelf();
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect([new Module(['slug' => 'ai-assistant']), new Module(['slug' => 'project-management'])]));
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->willReturnCallback(static fn (User $actor, int $organizationId): Builder => Project::query()->where('projects.organization_id', $organizationId));
        $policy = new AssistantDataAccessPolicy($authorization, $projects, $modules);
        $reader = new AssistantDomainReadService($catalog, $policy, $authorization);
        $registry = new AIToolRegistry;
        foreach ([new SearchAssistantDomainTool($catalog, $reader), new ReadAssistantDomainTool($catalog, $reader), new NavigationAssistantDomainTool($catalog, $reader), new DiscoverAssistantDomainCapabilitiesTool($catalog, $policy, $authorization)] as $tool) {
            $registry->registerTool($tool);
        }
        $permissions = $this->createMock(AIPermissionChecker::class);
        $permissions->method('canUseAssistant')->willReturn(true);
        $permissions->method('canExecuteTool')->willReturn(true);
        $context = $this->createMock(ContextBuilder::class);
        $context->method('buildSystemPrompt')->willReturn('Соблюдай текущие права и явный запрос пользователя.');
        $conversations = $this->createMock(ConversationManager::class);
        $conversations->method('getMessagesForContextWithBudget')->willReturn([]);
        $conversations->method('getSummary')->willReturn(null);
        $actor = $this->createPartialMock(User::class, ['belongsToOrganization']);
        $actor->method('belongsToOrganization')->willReturn(true);
        $actor->is_active = true;
        $actor->id = 7;
        $actor->current_organization_id = 15;
        $projectScope = $policy->entityQuery($actor, 15, 'project');
        if ($allowed) {
            $this->assertInstanceOf(Builder::class, $projectScope);
            $this->assertInstanceOf(Project::class, $projectScope->getModel());
            $this->assertStringContainsString('"assistant_acl_0" AS MATERIALIZED', $projectScope->toSql());
            $this->assertStringContainsString('"projects"."organization_id" = ?', $projectScope->toSql());
            $this->assertContains(15, $projectScope->getBindings());
        } else {
            $this->assertNull($projectScope);
        }
        $service = (new ReflectionClass(DiscoveryContextAssistantService::class))->newInstanceWithoutConstructor();
        foreach (['toolRegistry' => $registry, 'permissionChecker' => $permissions, 'activeActor' => $actor, 'contextBuilder' => $context, 'conversationManager' => $conversations, 'memoryService' => null, 'logging' => $this->createMock(LoggingService::class), 'toolEligibilityPolicy' => new AssistantToolEligibilityPolicy] as $property => $value) {
            (new ReflectionProperty(AIAssistantService::class, $property))->setValue($service, $value);
        }
        return $service;
    }
}

final class DiscoveryContextAssistantService extends AIAssistantService
{
    public function definitions(array $plan): array
    {
        return $this->resolveToolDefinitions($plan);
    }

    public function messages(array $plan): array
    {
        $conversation = new Conversation;
        $conversation->organization_id = 15;
        return $this->buildMessages($conversation, [], $plan);
    }
}
