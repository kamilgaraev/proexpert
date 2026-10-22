<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\DiscoverAssistantDomainCapabilitiesTool;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantReadToolCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenCounter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantReadToolCatalogTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_catalog_filters_effects_and_permissions_before_loading_tool_metadata(): void
    {
        $registry = $this->registry(['get_project_safety_summary', 'get_bim_model_elements',
            'generate_custom_report', 'approve_payment_request', 'unknown_read']);
        $registry->registerFactory('get_bim_model_elements', static fn () => throw new \LogicException('denied metadata loaded'));
        $catalog = new AssistantReadToolCatalog($registry, new AssistantToolEligibilityPolicy);
        $result = $catalog->describe(['read_tool_names' => ['get_project_safety_summary', 'approve_payment_request', 'get_bim_model_elements']],
            static fn (string $name): bool => $name !== 'get_bim_model_elements');

        self::assertSame(['get_project_safety_summary'], array_column($result['read_tools'], 'name'));
        self::assertSame(['get_project_safety_summary'], $result['selected_read_tools']);
        self::assertSame('read', $result['read_tools'][0]['effect']);
        self::assertSame(['get_project_safety_summary'], $catalog->index(static fn (string $name): bool => $name !== 'get_bim_model_elements')['names']);
    }

    public function test_catalog_page_bounds_permission_checks_and_continues_after_denied_tools(): void
    {
        $eligibility = new AssistantToolEligibilityPolicy;
        $policy = new ReflectionClass($eligibility);
        $names = array_slice($policy->getConstant('READ_TOOLS'), 0, 30);
        $checks = 0;
        $catalog = new AssistantReadToolCatalog($this->registry($names), $eligibility);
        $first = $catalog->describe([], static function () use (&$checks): bool { $checks++; return false; });

        self::assertSame(24, $checks);
        self::assertSame(24, $first['next_read_tool_offset']);
        self::assertSame([], $first['read_tools']);
        $second = $catalog->describe(['read_tool_offset' => 24, 'read_tool_limit' => 2], static fn (): bool => true);
        self::assertSame(array_slice($names, 24, 2), array_column($second['read_tools'], 'name'));
        self::assertSame(26, $second['next_read_tool_offset']);
        self::assertArrayNotHasKey('selected_read_tools', $second);
    }

    #[DataProvider('invalidRequests')]
    public function test_catalog_rejects_unbounded_or_invalid_requests(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssistantReadToolCatalog(new AIToolRegistry, new AssistantToolEligibilityPolicy))->describe($arguments, static fn (): bool => true);
    }

    public static function invalidRequests(): iterable
    {
        yield 'large page' => [['read_tool_limit' => 7]];
        yield 'negative offset' => [['read_tool_offset' => -1]];
        yield 'too many selected' => [['read_tool_names' => ['a', 'b', 'c', 'd']]];
        yield 'not a list' => [['read_tool_names' => ['forged' => 'get_project_snapshot']]];
        yield 'invalid name' => [['read_tool_names' => ['get_project_snapshot<script>']]];
    }

    #[DataProvider('unrecognizedQuestions')]
    public function test_model_selection_opens_allowed_read_without_regex_and_fresh_denial_removes_it(string $query): void
    {
        $registry = $this->registry(['assistant_domain_discover_capabilities', 'get_project_safety_summary',
            'get_bim_model_elements', 'generate_custom_report', 'approve_payment_request']);
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 15]);
        $allowed = true;
        $permissions = $this->createMock(AIPermissionChecker::class);
        $permissions->method('canExposeTool')->willReturnCallback(static function (User $user, string $name, bool $fresh) use (&$allowed): bool {
            self::assertFalse($fresh);
            return $name !== 'get_bim_model_elements' && ($name !== 'get_project_safety_summary' || $allowed);
        });
        $class = new ReflectionClass(AIAssistantService::class);
        $service = $class->newInstanceWithoutConstructor();
        foreach (['toolRegistry' => $registry, 'activeActor' => $actor, 'permissionChecker' => $permissions, 'dataAccess' => null,
            'logging' => $this->createMock(LoggingService::class), 'toolEligibilityPolicy' => new AssistantToolEligibilityPolicy] as $key => $value) {
            $class->getProperty($key)->setValue($service, $value);
        }
        $method = $class->getMethod('resolveToolDefinitions');
        $plan = ['request' => ['message' => $query, 'allow_actions' => false], 'capability' => []];
        self::assertNotContains('get_project_safety_summary', array_column($method->invoke($service, $plan), 'name'));
        $selection = ['get_project_safety_summary', 'get_bim_model_elements', 'approve_payment_request'];
        self::assertSame(['assistant_domain_discover_capabilities', 'get_project_safety_summary'],
            array_column($method->invoke($service, $plan, $selection), 'name'));
        $allowed = false;
        self::assertSame(['assistant_domain_discover_capabilities'], array_column($method->invoke($service, $plan, $selection), 'name'));
        self::assertSame([], AssistantReadToolCatalog::selection($registry->getToolNames(), ['unknown_read', 'generate_custom_report', 'approve_payment_request'], new AssistantToolEligibilityPolicy));
    }

    public static function unrecognizedQuestions(): iterable
    {
        yield ['Что может помешать людям спокойно завершить смену?'];
        yield ['Где у нас не всё благополучно для бригады?'];
        yield ['Подскажи, с чем коллегам стоит быть осторожнее.'];
    }

    public function test_names_only_index_does_not_load_schemas_and_has_bounded_size(): void
    {
        $policy = new AssistantToolEligibilityPolicy;
        $names = (new ReflectionClass($policy))->getConstant('READ_TOOLS');
        $registry = new AIToolRegistry;
        foreach ($names as $name) {
            $registry->registerFactory($name, static fn () => throw new \LogicException('schema loaded for index'));
        }
        $checks = 0;
        $index = (new AssistantReadToolCatalog($registry, $policy))->index(static function () use (&$checks): bool { $checks++; return true; });
        $tokens = (new TokenCounter)->value($index);

        self::assertSame(array_slice($names, 0, AssistantReadToolCatalog::MAX_INDEX), $index['names']);
        self::assertSame(min(count($names), AssistantReadToolCatalog::MAX_INDEX), $checks);
        self::assertLessThan(2000, $tokens);
        fwrite(STDOUT, "\nRead-tool index: {$checks} permission checks, {$tokens} tokens, no tool instantiation.\n");
    }

    public function test_index_limit_is_not_a_tool_allowlist_and_catalog_continues_after_it(): void
    {
        $names = ['get_project_snapshot', 'get_project_safety_summary', 'get_bim_model_elements'];
        $policy = new AssistantToolEligibilityPolicy;
        $catalog = new AssistantReadToolCatalog($this->registry($names), $policy);
        $checks = 0;
        $index = $catalog->index(static function () use (&$checks): bool { $checks++; return true; }, 2);

        self::assertSame(2, $checks);
        self::assertSame(2, $index['next_read_tool_offset']);
        $page = $catalog->describe(['read_tool_offset' => $index['next_read_tool_offset'], 'read_tool_names' => ['get_bim_model_elements']], static fn (): bool => true);
        self::assertSame(['get_bim_model_elements'], array_column($page['read_tools'], 'name'));
        self::assertSame(['get_bim_model_elements'], $page['selected_read_tools']);
        self::assertNull($page['next_read_tool_offset']);
    }

    public function test_production_lazy_registry_resolves_discovery_without_dependency_cycle(): void
    {
        (new AIAssistantServiceProvider(app()))->register();
        app()->instance(AssistantDataAccessPolicy::class, (new ReflectionClass(AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor());
        app()->instance(AIPermissionChecker::class, $this->createMock(AIPermissionChecker::class));
        $registry = app(AIToolRegistry::class);
        $discovery = $registry->getTool('assistant_domain_discover_capabilities');

        self::assertInstanceOf(DiscoverAssistantDomainCapabilitiesTool::class, $discovery);
        self::assertSame($discovery, $registry->getTool('assistant_domain_discover_capabilities'));
        self::assertSame(AssistantReadToolCatalog::MAX_SELECTED, $discovery->getParametersSchema()['properties']['read_tool_names']['maxItems']);
    }

    private function registry(array $names): AIToolRegistry
    {
        $registry = new AIToolRegistry;
        foreach ($names as $name) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getDescription')->willReturn('Разрешённое чтение');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
            $registry->registerTool($tool);
        }

        return $registry;
    }
}
