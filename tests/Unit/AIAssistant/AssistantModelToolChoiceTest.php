<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Models\User;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantModelToolChoiceTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('requests')]
    public function test_model_can_choose_search_and_follow_up_reads_regardless_of_backend_route(string $query, string $capability): void
    {
        $registry = new AIToolRegistry;
        $names = ['search_estimate_positions', 'get_estimate_answer', 'get_estimate_positions',
            'assistant_domain_discover_capabilities', 'assistant_domain_search', 'assistant_domain_read',
            'get_contract_snapshot', 'search_users', 'approve_payment_request', 'generate_operational_pdf_report'];
        foreach ($names as $name) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getDescription')->willReturn($name);
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
            $registry->registerTool($tool);
        }
        $actor = new User;
        $permissions = $this->createMock(AIPermissionChecker::class);
        $permissions->method('canExposeTool')->willReturnCallback(static fn (User $user, string $name): bool => $name !== 'search_users');
        $class = new ReflectionClass(AIAssistantService::class);
        $service = $class->newInstanceWithoutConstructor();
        foreach (['toolRegistry' => $registry, 'activeActor' => $actor, 'permissionChecker' => $permissions, 'dataAccess' => null,
            'logging' => $this->createMock(LoggingService::class), 'toolEligibilityPolicy' => new AssistantToolEligibilityPolicy] as $property => $value) {
            $class->getProperty($property)->setValue($service, $value);
        }
        $plan = ['task_type' => 'find', 'capability' => ['id' => $capability],
            'request' => ['message' => $query, 'allow_actions' => false],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray()];
        $definitions = $class->getMethod('resolveToolDefinitions')->invoke($service, $plan);

        self::assertSame(array_slice($names, 0, 7), array_column(array_column($definitions, 'function'), 'name'));
    }

    public static function requests(): iterable
    {
        yield 'production' => ['найди в любой смете цену на 1м3 бетона', 'estimates'];
        yield 'plural' => ['найди во всех сметах цену на 1м3 бетона', 'estimates'];
        yield 'stale route' => ['В сметах найди бетон и его цену за кубометр', 'warehouse'];
        yield 'composition' => ['Покажи ресурсный состав позиции сметы', 'warehouse'];
        yield 'payment question can read related contract' => ['Что с платежами? Только текст.', 'payments'];
        yield 'unrecognized wording' => ['Мне нужна расценка на куб бетона из наших расчётов', 'unknown'];
    }
}
