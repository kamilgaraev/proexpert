<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCapabilityRegistry;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimatePositionsTool;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantEstimateCompositionRoutingTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('compositionRequests')]
    public function test_composition_request_selects_estimates_and_exposes_the_resource_tool(string $query, array $context): void
    {
        $registry = new AssistantCapabilityRegistry;
        $capability = $registry->match($query, $context);
        $understanding = (new AssistantRequestUnderstandingResolver($registry))->resolve($query, $context);

        self::assertSame('estimates', $capability['id']);
        self::assertSame('estimates', array_values(array_filter($understanding->evidence,
            static fn (array $entry): bool => $entry['type'] === 'primary_domain'))[0]['value']);
        self::assertSame('read_only', $understanding->actionPolicy);
        $names = $this->toolNames($query, $capability['id']);
        self::assertContains('get_estimate_positions', $names);
        self::assertNotContains('get_estimate_answer', $names);
        self::assertNotContains('get_material_stock', $names);
        self::assertNotContains('search_warehouse', $names);
        self::assertTrue((new AssistantToolEligibilityPolicy)->canExposeTool('get_estimate_positions', $understanding)->allowed);
    }

    public function test_composition_tool_remains_available_when_primary_capability_is_stale_warehouse(): void
    {
        $names = $this->toolNames(self::productionQuery(), 'warehouse');

        self::assertContains('get_estimate_positions', $names);
        self::assertNotContains('get_estimate_answer', $names);
        self::assertNotContains('get_material_stock', $names);
    }

    #[DataProvider('permissionDecisions')]
    public function test_composition_schema_exposure_keeps_the_current_permission_gate(bool $allowed): void
    {
        $actor = new User;
        $checker = $this->createMock(AIPermissionChecker::class);
        $checker->expects(self::once())->method('canExposeTool')->with($actor, 'get_estimate_positions', false)->willReturn($allowed);
        $registry = new AIToolRegistry;
        $registry->registerTool((new ReflectionClass(GetEstimatePositionsTool::class))->newInstanceWithoutConstructor());
        $class = new ReflectionClass(AIAssistantService::class);
        $service = $class->newInstanceWithoutConstructor();
        foreach (['activeActor' => $actor, 'permissionChecker' => $checker, 'dataAccess' => null, 'toolRegistry' => $registry] as $property => $value) {
            $class->getProperty($property)->setValue($service, $value);
        }

        $definitions = $class->getMethod('resolveToolDefinitions')->invoke($service, [
            'task_type' => 'summary', 'capability' => ['id' => 'warehouse'], 'request' => ['message' => self::productionQuery()],
        ]);

        self::assertSame($allowed ? ['get_estimate_positions'] : [], array_column(array_column($definitions, 'function'), 'name'));
        if ($allowed) {
            self::assertSame('boolean', $definitions[0]['function']['parameters']['properties']['include_composition']['type']);
        }
    }

    #[DataProvider('otherRequests')]
    public function test_stock_financial_and_explanation_requests_keep_their_tools(string $query, string $capabilityId, string $expectedTool): void
    {
        $names = $this->toolNames($query, $capabilityId);

        self::assertContains($expectedTool, $names);
        if ($capabilityId === 'warehouse') {
            self::assertNotContains('get_estimate_positions', $names);
        }
    }

    public static function compositionRequests(): iterable
    {
        yield 'production prompt despite warehouse route' => [self::productionQuery(), [
            'source_module' => 'ai-assistant', 'source_route' => '/warehouse',
            'ui_state' => ['assistant_path' => '/warehouse'],
        ]];
        yield 'other estimate and position' => ['В смете ЛОК-15 перечисли ресурсы позиции 2.4, их количества и цены.', []];
        yield 'composition without resource wording' => ['Покажи состав позиции сметы Р-17: труд и материалы.', []];
        yield 'selected estimate' => ['Какие материалы входят в состав позиции 1?', ['selected_estimate_id' => 19]];
        yield 'estimate resource composition' => ['Покажи ресурсный состав сметы ЛОК-8.', []];
    }

    public static function otherRequests(): iterable
    {
        yield 'stock' => ['Покажи остатки материалов на складе.', 'warehouse', 'get_material_stock'];
        yield 'stock comparison' => ['Сравни складские остатки с потребностью сметы.', 'warehouse', 'search_warehouse'];
        yield 'estimate totals' => ['Какая итоговая стоимость сметы?', 'estimates', 'get_estimate_answer'];
        yield 'explanation' => ['Что такое ресурсный состав позиции сметы?', 'estimates', 'search_assistant_documents'];
        yield 'knowledge base' => ['Найди в базе знаний сведения о ресурсном составе позиции сметы.', 'estimates', 'search_assistant_documents'];
    }

    public static function permissionDecisions(): iterable
    {
        yield 'allowed' => [true];
        yield 'denied' => [false];
    }

    private static function productionQuery(): string
    {
        return 'В смете СМ-2026-0009 покажи ресурсный состав первой позиции ГЭСН08-02-002-05: труд, машины и материалы, их количества, единицы и стоимость.';
    }

    private function toolNames(string $query, string $capabilityId): array
    {
        $class = new ReflectionClass(AIAssistantService::class);

        return $class->getMethod('resolveRelevantToolNames')->invoke($class->newInstanceWithoutConstructor(), [
            'task_type' => 'summary', 'capability' => ['id' => $capabilityId], 'request' => ['message' => $query],
        ]);
    }
}
