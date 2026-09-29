<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateOperationalPdfReportTool;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\AssistantToolArgumentValidator;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportPeriodFilter;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportCatalog;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantToolEligibilityPolicy;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class UserAskedMutationProposalTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_explicit_mutation_builds_confirmation_proposal_without_executing_tool(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('create_measurement_unit');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]);
        $tool->expects($this->never())->method('execute');
        $checker = $this->checker(true);
        $checker->expects($this->once())->method('canExecuteTool')->willReturn(true);
        $service = $this->service($tool, $checker);
        $plan = $this->plan('Создай единицу измерения упаковка');

        $result = $service->call($plan, true, ['name' => 'упаковка']);

        $this->assertSame('pending_confirmation', $result['response']['status']);
        $this->assertSame([], $result['failures']);
        $this->assertNull($result['executed_action']);
        $this->assertCount(1, $result['proposals']);
        $this->assertSame('create_measurement_unit', $result['proposals'][0]['tool_name']);
        $this->assertSame(['name' => 'упаковка'], $result['proposals'][0]['arguments']);
        $this->assertTrue($result['proposals'][0]['allowed']);
        $this->assertTrue($result['proposals'][0]['requires_confirmation']);
        $understanding = (new AssistantRequestUnderstandingResolver)->resolve('Создай единицу измерения упаковка', []);
        $this->assertFalse((new AssistantToolEligibilityPolicy)->canExecuteTool('create_measurement_unit', $understanding, true)->allowed);
    }

    #[DataProvider('blockedRequests')]
    public function test_unrequested_or_disabled_mutation_never_builds_proposal(?string $query, bool $allowActions): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('create_measurement_unit');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->never())->method('execute');
        $checker = $this->checker(true);
        $checker->expects($this->never())->method('canExecuteTool');
        $plan = $query === null ? [] : $this->plan($query);
        $plan['request']['context']['document_text'] = 'Создай единицу измерения упаковка';

        $result = $this->service($tool, $checker)->call($plan, $allowActions);

        $this->assertSame('blocked_by_request_policy', $result['response']['status']);
        $this->assertSame([], $result['proposals']);
        $this->assertNull($result['executed_action']);
    }

    public static function blockedRequests(): array
    {
        return [
            'actions disabled' => ['Создай единицу измерения упаковка', false],
            'document instruction only' => ['Покажи единицы измерения', true],
            'explicit no actions' => ['Создай единицу измерения, но без действий', true],
            'mismatched user intent' => ['Удалить единицу измерения упаковка', true],
            'missing understanding' => [null, true],
            'missing understanding and disabled actions' => [null, false],
        ];
    }

    public function test_mutation_without_current_tool_permission_remains_disabled_and_unexecuted(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('create_measurement_unit');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->never())->method('execute');
        $checker = $this->checker(true);
        $checker->expects($this->once())->method('canExecuteTool')->willReturn(false);

        $result = $this->service($tool, $checker)->call($this->plan('Создай единицу измерения упаковка'), true);

        $this->assertFalse($result['proposals'][0]['allowed']);
        $this->assertTrue($result['proposals'][0]['requires_confirmation']);
        $this->assertNotEmpty($result['failures']);
        $this->assertNull($result['executed_action']);
    }

    public function test_report_without_current_permission_does_not_execute_or_propose(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('generate_operational_pdf_report');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->never())->method('execute');
        $checker = $this->checker(false);
        $checker->expects($this->once())->method('canExecuteTool')->willReturn(false);

        $result = $this->service($tool, $checker)->call($this->plan('Создай PDF отчет по проектам'), false);

        $this->assertArrayHasKey('error', $result['response']);
        $this->assertSame([], $result['proposals']);
    }

    #[DataProvider('blockedReportRequests')]
    public function test_report_negative_constraints_or_missing_user_request_block_execution(string $query): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('generate_operational_pdf_report');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->never())->method('execute');
        $checker = $this->checker(false);
        $checker->expects($this->never())->method('canExecuteTool');

        $result = $this->service($tool, $checker)->call($this->plan($query), false);

        $this->assertSame('blocked_by_request_policy', $result['response']['status']);
        $this->assertSame([], $result['proposals']);
    }

    public static function blockedReportRequests(): array
    {
        return [['Покажи проекты. Только текст, без файла.'], ['Создай PDF отчет по проектам, но без действий'], ['Покажи краткую сводку по проектам']];
    }

    public function test_explicit_report_reaches_real_report_tool_without_business_mutation_proposal(): void
    {
        $tool = (new ReflectionClass(GenerateOperationalPdfReportTool::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($tool, 'reportCatalog'))->setValue($tool, new AssistantReportCatalog);
        (new ReflectionProperty($tool, 'periodFilter'))->setValue($tool, new AssistantOperationalReportPeriodFilter);
        $checker = $this->checker(false);
        $checker->expects($this->exactly(2))->method('canExecuteTool')->willReturn(true);
        app()->instance(AIPermissionChecker::class, $checker);
        $result = $this->service($tool, $checker)->call($this->plan('Создай PDF отчет по проектам'), false, ['report_type' => 'unsupported_test_report']);

        $this->assertFalse((new AIPermissionChecker)->isMutationTool($tool->getName()));
        $this->assertSame('error', $result['response']['status']);
        $this->assertSame('Не удалось определить тип отчета.', $result['response']['message']);
        $this->assertSame([], $result['proposals']);
        $this->assertSame([], $result['failures']);
        $this->assertNull($result['executed_action']);
    }

    #[DataProvider('deniedBusinessRequests')]
    public function test_server_tool_denial_makes_compound_and_nonfinancial_answers_nonbillable(string $query): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_contract_snapshot');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->expects($this->never())->method('execute');
        $checker = $this->checker(false);
        $checker->method('canExecuteTool')->willReturn(false);
        $service = $this->service($tool, $checker);

        $service->call($this->plan($query), false);
        $result = $service->publication();

        $this->assertSame('access_denied', $result['metadata']['outcome']);
        $this->assertTrue($result['metadata']['access_denied']);
        $this->assertFalse($result['useful']);
    }

    public static function deniedBusinessRequests(): array
    {
        return [['Прочитай текст договора и покажи его сумму'], ['Покажи описание договора']];
    }

    #[DataProvider('failedToolResults')]
    public function test_server_tool_failure_remains_nonbillable_even_with_partial_data(array $toolResult): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_contract_snapshot');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->method('execute')->willReturn($toolResult);
        $checker = $this->checker(false);
        $checker->method('canExecuteTool')->willReturn(true);
        $service = $this->service($tool, $checker);

        $service->call($this->plan('Прочитай текст договора и покажи сумму'), false);

        $result = $service->publication();
        $this->assertSame('service_error', $result['metadata']['outcome']);
        $this->assertTrue($result['metadata']['service_error']);
        $this->assertFalse($result['useful']);
    }

    public static function failedToolResults(): array
    {
        return ['status error' => [['status' => 'error', 'rows' => [['name' => 'Частичные данные']]]], 'error without status' => [['error' => 'Service unavailable']]];
    }

    #[DataProvider('financialInsufficiencyResults')]
    public function test_finite_financial_insufficiency_is_free_without_becoming_a_backend_failure(string $name, array $toolResult): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getParametersSchema')->willReturn(['type' => 'object']);
        $tool->method('execute')->willReturn($toolResult);
        $checker = $this->checker(false);
        $checker->method('canExecuteTool')->willReturn(true);
        $service = $this->service($tool, $checker);
        $service->call(['request' => ['message' => 'Покажи точную сумму проекта']], false);
        $publication = $service->publication();
        $this->assertSame('insufficient_data', $publication['metadata']['outcome']);
        $this->assertFalse($publication['metadata']['service_error']);
        $this->assertFalse($publication['metadata']['access_denied']);
        $this->assertFalse($publication['useful']);
    }

    public static function financialInsufficiencyResults(): array
    {
        $cases = [];
        foreach (['get_published_report_financial_evidence', 'get_live_project_financial_evidence'] as $name) {
            foreach ([['status' => 'insufficient_data'], ['status' => 'partial', 'rows' => [['actual' => '1.00']]], ['status' => 'incomplete'], ['status' => 'success', 'useful' => false]] as $index => $result) {
                $cases[$name.'_'.$index] = [$name, $result];
            }
        }
        return $cases;
    }

    public function test_no_available_business_tool_is_nonbillable_but_capability_question_is_useful(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_contract_snapshot');
        $service = $this->service($tool, $this->checker(false));
        $plan = $this->plan('Прочитай текст договора и покажи сумму');
        $plan['capability'] = ['id' => 'contracts'];
        $result = $service->publication($plan);
        $this->assertSame('service_error', $result['metadata']['outcome']);
        $this->assertFalse($result['useful']);

        $generic = $this->service($tool, $this->checker(false));
        $plan = $this->plan('Что ты умеешь?');
        $plan['capability'] = ['id' => 'contracts'];
        $result = $generic->publication($plan);
        $this->assertArrayNotHasKey('outcome', $result['metadata']);
        $this->assertTrue($result['useful']);
        $plan = $this->plan('Что ты умеешь с договорами?');
        $plan['capability'] = ['id' => 'contracts', 'domain' => 'contracts'];
        $this->assertTrue($generic->publication($plan)['useful']);
    }

    public function test_read_tools_do_not_make_disabled_business_mutation_billable(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('assistant_domain_read');
        $service = $this->service($tool, $this->checker(false));
        $plan = $this->plan('Создай единицу измерения упаковка');
        $plan['request']['allow_actions'] = false;
        $plan['capability'] = ['id' => 'measurement_units'];
        $service->unavailable($plan, [['function' => ['name' => 'assistant_domain_read']]]);

        $result = $service->publication();

        $this->assertSame('request_blocked', $result['metadata']['outcome']);
        $this->assertFalse($result['useful']);
    }

    private function plan(string $query): array
    {
        return ['request' => ['message' => $query], 'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query, [])->toArray()];
    }

    private function checker(bool $mutation): AIPermissionChecker
    {
        $checker = $this->createMock(AIPermissionChecker::class);
        $checker->method('isMutationTool')->willReturn($mutation);
        return $checker;
    }

    private function service(AIToolInterface $tool, AIPermissionChecker $checker): ProposalBoundaryAssistantService
    {
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = (new ReflectionClass(ProposalBoundaryAssistantService::class))->newInstanceWithoutConstructor();
        foreach (['toolRegistry' => $registry, 'permissionChecker' => $checker, 'logging' => $this->createMock(LoggingService::class), 'toolArguments' => new AssistantToolArgumentValidator, 'toolEligibilityPolicy' => new AssistantToolEligibilityPolicy, 'legacyLiveEvidence' => null] as $property => $value) {
            (new ReflectionProperty(AIAssistantService::class, $property))->setValue($service, $value);
        }
        $service->toolName = $tool->getName();
        return $service;
    }
}

final class ProposalBoundaryAssistantService extends AIAssistantService
{
    public string $toolName;

    public function unavailable(array $plan, array $tools): void
    {
        (new \ReflectionMethod(AIAssistantService::class, 'recordUnavailableBusinessTools'))->invoke($this, $plan, $tools);
    }

    public function publication(?array $plan = null): array
    {
        if ($plan !== null) {
            (new \ReflectionMethod(AIAssistantService::class, 'recordUnavailableBusinessTools'))->invoke($this, $plan, []);
        }
        $metadata = (new \ReflectionMethod(AIAssistantService::class, 'decorateMetadata'))->invoke($this, ['needs_clarification' => false], new User);
        $useful = (new \ReflectionMethod(AIAssistantService::class, 'isUsefulAnswer'))->invoke($this, ['message' => ['content' => 'Полезный ответ от модели, независимо от серверного отказа.', 'metadata' => $metadata]]);
        return ['metadata' => $metadata, 'useful' => $useful];
    }

    public function call(array $plan, bool $allowActions, array $arguments = []): array
    {
        $organization = new Organization;
        $organization->id = 15;
        $actor = new User;
        $actor->id = 7;
        $actor->current_organization_id = 15;
        $executed = null;
        $evidence = $failures = $proposals = $urls = [];
        $response = $this->handleToolCall(['function' => ['name' => $this->toolName, 'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)]], $organization, $actor, 15, $plan, $allowActions, $executed, $evidence, $failures, $proposals, $urls);
        return ['response' => $response, 'proposals' => $proposals, 'failures' => $failures, 'executed_action' => $executed];
    }
}
