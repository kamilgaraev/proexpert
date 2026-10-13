<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentExecutor;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentPlanner;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantAgentStateStore;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantArtifactNormalizer;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantCapabilityCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantResponseVerifier;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AIToolRegistry;
use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantRequestUnderstandingResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantTaskOrchestrator;
use App\BusinessModules\Features\AIAssistant\Services\ContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\IntentRecognizer;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

interface NativeResponsesTestProvider extends LLMProviderInterface
{
    public function responses(array $input, array $options = []): array;
}

class AIAssistantServiceBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $application = new \Illuminate\Foundation\Application(dirname(__DIR__, 3));
        $application->instance('config', new \Illuminate\Config\Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru'], 'ai-assistant-credits' => require dirname(__DIR__, 3).'/config/ai-assistant-credits.php']));
        $application->instance('translator', new \Illuminate\Translation\Translator(new \Illuminate\Translation\FileLoader(new \Illuminate\Filesystem\Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        $application->instance('log', new \Psr\Log\NullLogger);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($application);
    }

    public function test_empty_named_project_search_uses_fresh_scoped_result_without_equipment_claims(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $actor = new User(['current_organization_id' => 15]);
        $result = ['_tool_name' => 'search_projects', 'status' => 'success', 'results' => [],
            'result_window' => ['returned' => 0, 'has_more' => false]];
        $service->freshProjectSearch = $result;
        $answer = $service->exposeVerifyEmptyNamedProjectSearch('У нас в проекте СевГУ есть кондиционеры, сколько их?', [$result], $actor, 15);
        self::assertSame(['СевГУ', 15], $service->freshProjectSearchCalls[0]);
        self::assertSame(trans_message('ai_assistant.project_search_no_matches'), $answer['text']);
        self::assertSame([], $answer['source_refs']);
        self::assertSame('partial', $answer['validation_status']);
        self::assertTrue($answer['needs_clarification']);
        self::assertStringNotContainsString('кондиционер', $answer['text']);
        $service->freshProjectSearch['results'] = [['id' => 52]];
        self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте СевГУ сколько кондиционеров?', [$result], $actor, 15));
        $service->freshProjectSearch = null;
        self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте СевГУ сколько кондиционеров?', [$result], $actor, 15));
    }

    public function test_no_match_message_rejects_incomplete_failed_and_unscoped_project_searches(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $actor = new User(['current_organization_id' => 15]);
        $empty = ['_tool_name' => 'search_projects', 'status' => 'success', 'results' => [],
            'result_window' => ['returned' => 0, 'has_more' => false]];
        foreach ([array_replace($empty, ['status' => 'error']), array_replace($empty, ['error' => 'denied']),
            array_replace($empty, ['results' => [['id' => 52]]]), array_replace($empty, ['_tool_name' => 'search_estimates']),
            array_replace($empty, ['source_refs' => [['entity_id' => 52]]]),
            array_replace($empty, ['result_window' => ['returned' => 0, 'has_more' => true]])] as $result) {
            self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте СевГУ сколько кондиционеров?', [$result], $actor, 15));
        }
        self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В текущем проекте сколько кондиционеров?', [$empty], $actor, 15));
        self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте СевГУ сколько кондиционеров?', [$empty, $empty], $actor, 15));
        self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте СевГУ сколько кондиционеров?', [$empty], $actor, 99));
        self::assertSame([], $service->freshProjectSearchCalls);
    }

    public function test_empty_project_search_uses_verified_bim_package_in_current_project(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $actor = new User(['current_organization_id' => 15]);
        $empty = ['_tool_name' => 'search_projects', 'status' => 'success', 'results' => [],
            'result_window' => ['returned' => 0, 'has_more' => false]];
        $service->freshProjectSearch = $empty;
        $service->bimAliasAnswer = ['text' => 'Комплект Севгу в проекте Тестовый: 5 моделей.', 'validation_status' => 'verified',
            'needs_clarification' => false, 'source_refs' => [['entity_type' => 'design_artifact_version', 'entity_id' => 19, 'project_id' => 52]]];
        $answer = $service->exposeVerifyEmptyNamedProjectSearch('В проекте Севгу сколько кондиционеров?', [$empty], $actor, 15, 52);
        self::assertSame($service->bimAliasAnswer, $answer);
        self::assertSame([['Севгу', 52]], $service->bimFallbackCalls);
        foreach (['на втором этаже', 'с мощностью больше 5 кВт', 'из металла', 'в версии 3', 'вместе с насосами', ', насосов'] as $condition) {
            self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте Севгу сколько кондиционеров '.$condition.'?', [$empty], $actor, 15, 52));
        }
        self::assertCount(1, $service->bimFallbackCalls);
        $service->freshProjectSearch = ['status' => 'success', 'results' => [['id' => 95]], 'result_window' => ['returned' => 1, 'has_more' => false]];
        self::assertNull($service->exposeVerifyEmptyNamedProjectSearch('В проекте Севгу сколько кондиционеров?', [$empty], $actor, 15, 52));
        self::assertCount(1, $service->bimFallbackCalls);
    }

    public function test_generic_request_exposes_reads_without_exposing_unconfirmed_actions(): void
    {
        $toolRegistry = new AIToolRegistry;
        $toolRegistry->registerTool($this->makeTool('search_projects'));
        $toolRegistry->registerTool($this->makeTool('create_schedule_task'));

        $service = $this->makeService($toolRegistry);

        $tools = $service->exposeResolveToolDefinitions([
            'task_type' => 'summary',
            'capability' => null,
            'request' => [
                'allow_actions' => false,
                'context' => [],
            ],
        ]);

        $this->assertSame(['search_projects'], array_column($tools, 'name'));
    }

    public function test_classroom_calculation_has_no_business_tools_or_reference_context(): void
    {
        $query = 'Для учебного примера вычисли 12 + 8 + 5. Это условные числа, не данные сметы или проекта.';
        $registry = new AIToolRegistry;
        $registry->registerTool($this->makeTool('search_projects'));
        $registry->registerTool($this->makeTool('get_contract_snapshot'));
        $history = [['role' => 'assistant', 'content' => 'Учебный результат: 25.']];
        $service = $this->makeService($registry, history: $history);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects($this->once())->method('getMessagesForContextWithBudget')->with(
            $this->anything(), $this->anything(), $this->anything(), $this->anything(),
            $this->anything(), $this->anything(), false, true,
        );
        $manager->expects($this->never())->method('getSummary');
        (new \ReflectionProperty(AIAssistantService::class, 'activeActor'))->setValue($service, new User);
        (new \ReflectionProperty(AIAssistantService::class, 'activeEstimateSelection'))->setValue($service, ['estimate_id' => 777]);
        (new \ReflectionProperty(AIAssistantService::class, 'precomputedCapabilityHints'))->setValue($service, ['domains' => str_repeat('БИЗНЕС-КАТАЛОГ ', 1600)]);
        $plan = ['request' => ['message' => $query, 'context' => ['source_module' => 'estimates', 'entity_refs' => [['type' => 'estimate', 'id' => 777]]]]];

        self::assertSame([], $service->exposeResolveToolDefinitions($plan));
        $messages = $service->exposeBuildMessages(new \App\BusinessModules\Features\AIAssistant\Models\Conversation, $plan, $query);
        self::assertContains($history[0], $messages);
        self::assertSame($query, $messages[array_key_last($messages)]['content']);
        $serialized = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('selected_estimate', $serialized);
        self::assertStringNotContainsString('task_context', $serialized);
        self::assertStringNotContainsString('БИЗНЕС-КАТАЛОГ', $serialized);
        [$prepared, $options] = $service->exposePrepareProviderPayload($messages, ['budget_profile' => 'normal', 'tools' => []]);
        self::assertSame($query, $prepared[array_key_last($prepared)]['content']);
        self::assertLessThanOrEqual(16384, $options['estimated_input_tokens']);
    }

    public function test_domain_capabilities_expose_snapshot_tools(): void
    {
        $toolRegistry = new AIToolRegistry;
        foreach ([
            'get_project_snapshot',
            'get_procurement_snapshot',
            'get_contract_snapshot',
            'get_schedule_snapshot',
        ] as $toolName) {
            $toolRegistry->registerTool($this->makeTool($toolName));
        }

        $service = $this->makeService($toolRegistry);

        $tools = $service->exposeResolveToolDefinitions([
            'task_type' => 'analyze',
            'capability' => [
                'id' => 'procurement',
            ],
            'request' => [
                'allow_actions' => false,
                'context' => [],
            ],
        ]);

        $toolNames = array_map(
            static fn (array $definition): string => (string) $definition['name'],
            $tools
        );

        $this->assertContains('get_procurement_snapshot', $toolNames);
        $this->assertContains('get_project_snapshot', $toolNames);
    }

    public function test_payment_classification_keeps_model_selected_related_reads_available(): void
    {
        $toolRegistry = new AIToolRegistry;
        foreach ([
            'assistant_domain_search',
            'assistant_domain_read',
            'assistant_domain_navigation',
            'assistant_domain_discover_capabilities',
            'search_assistant_documents',
            'get_estimate_answer',
            'get_material_stock',
            'get_published_report_financial_evidence',
            'get_live_project_financial_evidence',
            'generate_contract_payments_report',
            'get_contract_snapshot',
            'get_project_snapshot',
            'search_projects',
            'get_schedule_snapshot',
        ] as $toolName) {
            $toolRegistry->registerTool($this->makeTool($toolName));
        }

        $service = $this->makeService($toolRegistry);
        $resolver = new AssistantRequestUnderstandingResolver;
        $request = [
            'allow_actions' => false,
            'context' => [],
        ];
        $paymentPlan = [
            'task_type' => 'find',
            'capability' => ['id' => 'payments', 'domain' => 'finance'],
            'request' => $request,
            'request_understanding' => $resolver->resolve('Что с платежами?')->toArray(),
        ];
        $paymentToolNames = array_column($service->exposeResolveToolDefinitions($paymentPlan), 'name');

        $this->assertContains('assistant_domain_search', $paymentToolNames);
        $this->assertContains('get_contract_snapshot', $paymentToolNames);
        $this->assertContains('get_project_snapshot', $paymentToolNames);
        $this->assertContains('search_projects', $paymentToolNames);
        $this->assertContains('get_schedule_snapshot', $paymentToolNames);
        $this->assertNotContains('generate_contract_payments_report', $paymentToolNames);
        foreach ([
            'assistant_domain_discover_capabilities',
            'search_assistant_documents',
            'get_estimate_answer',
            'get_material_stock',
            'get_published_report_financial_evidence',
            'get_live_project_financial_evidence',
        ] as $toolName) {
            $this->assertContains($toolName, $paymentToolNames);
        }

        $contractPlan = $paymentPlan;
        $contractPlan['task_type'] = 'summary';
        $contractPlan['request_understanding'] = $resolver->resolve('Покажи платежи по договору')->toArray();
        $contractToolNames = array_column($service->exposeResolveToolDefinitions($contractPlan), 'name');

        $this->assertContains('get_contract_snapshot', $contractToolNames);

        $reportPlan = $paymentPlan;
        $reportPlan['task_type'] = 'summary';
        $reportPlan['request_understanding'] = $resolver->resolve('Сделай отчет по платежам')->toArray();
        $this->assertSame('generate_report', $reportPlan['request_understanding']['primary_intent']);
        $reportToolNames = array_column($service->exposeResolveToolDefinitions($reportPlan), 'name');
        $this->assertContains('generate_contract_payments_report', $reportToolNames);
        $this->assertContains('get_live_project_financial_evidence', $reportToolNames);

        $mixedPlan = $paymentPlan;
        $mixedPlan['task_type'] = 'summary';
        $mixedPlan['request_understanding'] = $resolver->resolve('Покажи платежи проекта')->toArray();
        $mixedToolNames = array_column($service->exposeResolveToolDefinitions($mixedPlan), 'name');
        $this->assertContains('get_project_snapshot', $mixedToolNames);
        $this->assertContains('get_live_project_financial_evidence', $mixedToolNames);
        $this->assertContains('get_estimate_answer', $mixedToolNames);
    }

    public function test_global_document_gap_keeps_guarded_search_exposed_and_pdf_blocked(): void
    {
        $registry = new AIToolRegistry;
        foreach (['search_assistant_documents', 'generate_rag_pdf_report'] as $name) {
            $registry->registerTool($this->makeTool($name));
        }
        $service = $this->makeService($registry);
        (new \ReflectionProperty(AIAssistantService::class, 'documentContextBlocked'))->setValue($service, true);
        $query = 'Прочитай текст правил из документа.';
        $plan = ['task_type' => 'summary', 'capability' => ['id' => 'reports'],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
            'request' => ['message' => $query, 'context' => [], 'allow_actions' => false]];
        $names = array_column($service->exposeResolveToolDefinitions($plan), 'name');
        self::assertContains('search_assistant_documents', $names);
        self::assertNotContains('generate_rag_pdf_report', $names);
    }

    public function test_model_selected_related_read_still_requires_current_tool_permission(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_contract_snapshot');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [], 'additionalProperties' => false]);
        $tool->expects($this->once())->method('execute')->willReturn(['status' => 'success']);
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve('Что с платежами?')->toArray()];
        $call = ['type' => 'function_call', 'call_id' => 'call_contract', 'name' => 'get_contract_snapshot', 'arguments' => '{}'];
        $failures = [];
        $allowed = $this->makeService($registry, true)->exposeHandleToolCall($call, $plan, $failures);
        self::assertSame('success', $allowed['status']);
        $denied = $this->makeService($registry, false)->exposeHandleToolCall($call, $plan, $failures);
        self::assertArrayHasKey('error', $denied);
        self::assertNotEmpty($failures);
    }

    public function test_current_project_estimate_tools_use_the_ui_scope_even_with_another_model_project(): void
    {
        foreach (['resolve_estimate', 'get_estimate_answer'] as $name) {
            $arguments = ['query' => 'Выбери одну доступную смету.', 'project_id' => 95];
            $expected = $arguments;
            $expected['project_id'] = 52;
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [
                'query' => ['type' => 'string'], 'project_id' => ['type' => ['integer', 'null']],
            ], 'additionalProperties' => false]);
            $tool->expects($this->once())->method('execute')->with($expected, $this->anything(), $this->anything())->willReturn(['status' => 'success']);
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $query = 'Выбери одну доступную смету текущего проекта.';
            $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
                'request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            $failures = [];
            $result = $this->makeService($registry, true)->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'scope_'.$name, 'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)], $plan, $failures);
            self::assertSame('success', $result['status']);
            self::assertSame([], $failures);
        }
    }

    public function test_current_object_contract_snapshot_binds_omitted_null_and_foreign_model_project_to_ui_scope(): void
    {
        $schema = (new \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetContractSnapshotTool)->getParametersSchema();
        foreach ([[], ['project_id' => null], ['project_id' => 100]] as $arguments) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn('get_contract_snapshot');
            $tool->method('getParametersSchema')->willReturn($schema);
            $tool->expects($this->once())->method('execute')->with(['project_id' => 52], $this->anything(), $this->anything())->willReturn(['status' => 'success', 'contracts' => []]);
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $service = $this->makeService($registry, true);
            $plan = ['request' => ['message' => 'Что у нас по договорам на этом объекте? Выбери один и покажи номер, подрядчика, сроки и сумму с валютой.',
                'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
            $failures = [];
            self::assertSame('success', $service->exposeHandleToolCall(['name' => 'get_contract_snapshot',
                'arguments' => json_encode($arguments === [] ? new \stdClass : $arguments, JSON_THROW_ON_ERROR)], $plan, $failures)['status']);
            self::assertSame([], $failures);
        }
    }

    public function test_manager_attention_uses_verified_ui_project_and_closed_read_arguments(): void
    {
        $schema = (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Actions\Domains\GetProjectAttentionTool::class))->newInstanceWithoutConstructor()->getParametersSchema();
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_project_attention');
        $tool->method('getParametersSchema')->willReturn($schema);
        $tool->expects($this->once())->method('execute')->with(['project_id' => 52, 'focus' => 'all', 'limit' => 3], $this->anything(), $this->anything())
            ->willReturn(['status' => 'success', 'source_refs' => [], 'attention_evidence' => ['version' => str_repeat('a', 64)]]);
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $query = 'Что на нашем объекте сейчас требует моего внимания в первую очередь? Назови три главных проблемы.';
        $plan = ['request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $failures = [];
        $result = $this->makeService($registry, true)->exposeHandleToolCall(['name' => 'get_project_attention',
            'arguments' => json_encode(['project_id' => 999, 'focus' => 'quality', 'limit' => 20], JSON_THROW_ON_ERROR)], $plan, $failures);
        self::assertSame('success', $result['status']);
        self::assertSame([], $failures);
    }

    public function test_current_object_read_rejects_foreign_contract_before_provider_and_active_evidence(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('assistant_domain_read');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]);
        $tool->expects($this->once())->method('execute')->with(['id' => 315], $this->anything(), $this->anything())->willReturn([
            'results' => [['entity_type' => 'contract', 'id' => 315]],
            'source_refs' => [['entity_type' => 'contract', 'entity_id' => 315, 'project_id' => 100]],
        ]);
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = $this->makeService($registry, true);
        $plan = ['request' => ['message' => 'Покажи договор на этом объекте.', 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $failures = [];
        $result = $service->exposeHandleToolCall(['name' => 'assistant_domain_read', 'arguments' => '{"id":315}'], $plan, $failures);
        self::assertSame('current_project_scope_mismatch', $result['reason']);
        self::assertSame([], $result['source_refs']);
        self::assertArrayNotHasKey('results', $result);
        self::assertSame([], (new \ReflectionProperty(AIAssistantService::class, 'activeToolResults'))->getValue($service));
    }

    public function test_current_object_requires_unique_reference_instead_of_raw_project_context(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_contract_snapshot');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer']]]);
        $tool->expects($this->never())->method('execute');
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $failures = [];
        $result = $this->makeService($registry, true)->exposeHandleToolCall(['name' => 'get_contract_snapshot', 'arguments' => '{}'],
            ['request' => ['message' => 'Договоры на этом объекте?', 'context' => ['project_id' => 52]]], $failures);
        self::assertSame('current_project_required', $result['reason']);
        self::assertTrue($result['needs_clarification']);
        self::assertSame([], $result['source_refs']);
    }

    public function test_current_object_domain_project_read_uses_own_key_and_contract_search_uses_project_filter(): void
    {
        foreach (['assistant_domain_read', 'assistant_domain_search'] as $name) {
            $arguments = $name === 'assistant_domain_read' ? ['entity_type' => 'project', 'id' => 100]
                : ['entity_type' => 'contract', 'project_id' => null];
            $expected = $name === 'assistant_domain_read' ? ['entity_type' => 'project', 'id' => 52]
                : ['entity_type' => 'contract', 'project_id' => 52];
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn($name);
            $properties = ['entity_type' => ['type' => 'string']] + ($name === 'assistant_domain_read'
                ? ['id' => ['type' => 'integer']] : ['project_id' => ['type' => ['integer', 'null']]]);
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => $properties]);
            $tool->expects($this->once())->method('execute')->with($expected, $this->anything(), $this->anything())->willReturn([
                'source_refs' => [['entity_type' => $name === 'assistant_domain_read' ? 'project' : 'live_domain_search_count',
                    'entity_id' => 52, 'project_id' => $name === 'assistant_domain_read' ? null : 52]],
            ]);
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $failures = [];
            $result = $this->makeService($registry, true)->exposeHandleToolCall(['name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)], ['request' => ['message' => 'Договоры на этом объекте?',
                    'context' => ['entity_references' => [['entity_type' => 'project', 'entity_id' => '52']]]]], $failures);
            self::assertCount(1, $result['source_refs']);
            self::assertSame([], $failures);
        }
    }

    public function test_organization_and_explicit_entity_requests_preserve_model_scope(): void
    {
        foreach (['Выбери договор во всех проектах.', 'Покажи договор №315.', 'Покажи договоры проекта «СевГУ».'] as $question) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn('get_contract_snapshot');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['project_id' => ['type' => 'integer']]]);
            $tool->expects($this->once())->method('execute')->with(['project_id' => 100], $this->anything(), $this->anything())->willReturn([
                'source_refs' => [['entity_type' => 'contract', 'entity_id' => 315, 'project_id' => 100]],
            ]);
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $failures = [];
            $result = $this->makeService($registry, true)->exposeHandleToolCall(['name' => 'get_contract_snapshot', 'arguments' => '{"project_id":100}'],
                ['request' => ['message' => $question, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]], $failures);
            self::assertCount(1, $result['source_refs']);
            self::assertSame([], $failures);
        }
    }

    public function test_bim_project_question_without_verified_scope_does_not_read_all_organization_models(): void
    {
        foreach (['Сколько кондиционеров в текущем проекте?', 'У нас в проекте севгу есть кондиционеры, сколько их?'] as $query) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn('get_bim_model_elements');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [
                'model_query' => ['type' => ['string', 'null']], 'project_id' => ['type' => ['integer', 'null']],
            ], 'additionalProperties' => false]);
            $tool->expects($this->never())->method('execute');
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
                'request' => ['message' => $query, 'context' => []]];
            $failures = [];
            $result = $this->makeService($registry, true)->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'bim_scope', 'name' => 'get_bim_model_elements',
                'arguments' => '{"model_query":null,"project_id":null}'], $plan, $failures);
            self::assertSame('insufficient_data', $result['status']);
            self::assertSame([], $result['source_refs']);
            self::assertTrue($result['needs_clarification']);
        }
    }

    public function test_nullable_bim_project_uses_canonical_context_name_and_preserves_explicit_file(): void
    {
        foreach ([
            ['У нас в проекте севгу есть кондиционеры, сколько их?', 'севгу', null],
            ['У нас в проекте севгу есть кондиционеры, сколько их?', null, null],
            ['В текущем проекте есть кондиционеры, сколько их?', null, null],
            ["В проекте СевГУ проверь модель 'СевГУ'.", 'севгу', 'севгу'],
        ] as [$query, $inputSelector, $expectedSelector]) {
            $expected = ['model_query' => $expectedSelector, 'project_id' => 52];
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn('get_bim_model_elements');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [
                'model_query' => ['type' => ['string', 'null']], 'project_id' => ['type' => ['integer', 'null']],
            ], 'additionalProperties' => false]);
            $tool->expects($this->once())->method('execute')->with($expected, $this->anything(), $this->anything())->willReturn(['status' => 'success']);
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $service = $this->makeService($registry, true);
            $service->canonicalBimProjects = [52 => 'СевГУ: учебный корпус'];
            $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
                'request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52, 'label' => 'Поддельное имя']]]]];
            $failures = [];
            $result = $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'bim_scope', 'name' => 'get_bim_model_elements',
                'arguments' => json_encode(['model_query' => $inputSelector, 'project_id' => null], JSON_THROW_ON_ERROR)], $plan, $failures);
            self::assertSame('success', $result['status']);
        }
    }


    public function test_bim_package_alias_uses_only_current_verified_project_after_complete_negative_project_lookup(): void
    {
        foreach ([['empty', true], ['found', false], ['truncated', false], ['denied', false], ['other_context', false]] as [$mode, $expectedRead]) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn('get_bim_model_elements');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [
                'model_query' => ['type' => ['string', 'null']], 'project_id' => ['type' => ['integer', 'null']],
            ], 'additionalProperties' => false]);
            $tool->expects($expectedRead ? $this->once() : $this->never())->method('execute')
                ->with(['model_query' => 'Севгу', 'project_id' => 52], $this->anything(), $this->anything())->willReturn(['status' => 'success']);
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $service = $this->makeService($registry, true);
            $service->canonicalBimProjects = [52 => 'Тестовый'];
            $service->bimAliases = [52 => ['Севгу']];
            $service->freshProjectSearch = ['status' => 'success', 'results' => [], 'result_window' => ['returned' => 0, 'has_more' => false]];
            if ($mode === 'found') { $service->freshProjectSearch['results'] = [['id' => 95]]; }
            if ($mode === 'truncated') { $service->freshProjectSearch['result_window']['has_more'] = true; }
            if ($mode === 'denied') { $service->freshProjectSearch = null; }
            $query = 'В проекте Севгу есть кондиционеры, сколько их?';
            $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
                'request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => $mode === 'other_context' ? 95 : 52]]]]];
            $failures = [];
            $result = $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'bim_alias', 'name' => 'get_bim_model_elements',
                'arguments' => json_encode(['model_query' => null, 'project_id' => 52], JSON_THROW_ON_ERROR)], $plan, $failures);
            self::assertSame($expectedRead ? 'success' : 'insufficient_data', $result['status']);
        }
    }

    public function test_simple_equipment_question_binds_requested_kind_and_rejects_model_category_substitution(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_bim_model_elements');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [
            'project_id' => ['type' => ['integer', 'null']], 'model_query' => ['type' => ['string', 'null']],
            'equipment_kind' => ['type' => ['string', 'null']], 'element_query' => ['type' => ['string', 'null']],
            'material_query' => ['type' => ['string', 'null']], 'offset' => ['type' => 'integer'], 'limit' => ['type' => 'integer'],
        ], 'additionalProperties' => false]);
        $seen = [];
        $tool->expects($this->exactly(2))->method('execute')->willReturnCallback(static function (array $args) use (&$seen): array {
            $seen[] = $args;
            return ['status' => 'success'];
        });
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = $this->makeService($registry, true);
        $query = 'Сколько кондиционеров в модели?';
        $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
            'request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $failures = [];
        $result = $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'kind_bound', 'name' => 'get_bim_model_elements',
            'arguments' => json_encode(['project_id' => 52, 'model_query' => null, 'equipment_kind' => null,
                'element_query' => 'IFCBUILDINGELEMENTPROXY', 'material_query' => 'Подмена', 'offset' => 20, 'limit' => 20], JSON_THROW_ON_ERROR)], $plan, $failures);
        self::assertSame('success', $result['status']);
        self::assertSame(['project_id' => 52, 'model_query' => null, 'equipment_kind' => 'air_conditioner',
            'element_query' => null, 'material_query' => null, 'offset' => 0, 'limit' => 1], $seen[0]);
        $plan['request']['message'] = 'Покажи все кондиционеры в модели';
        $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'kind_list', 'name' => 'get_bim_model_elements',
            'arguments' => json_encode(['project_id' => 52, 'model_query' => null, 'equipment_kind' => null,
                'element_query' => 'IFCBUILDINGELEMENTPROXY', 'material_query' => 'Подмена', 'offset' => 20, 'limit' => 20], JSON_THROW_ON_ERROR)], $plan, $failures);
        self::assertSame('air_conditioner', $seen[1]['equipment_kind']);
        self::assertSame(20, $seen[1]['offset']);
        self::assertSame(20, $seen[1]['limit']);
    }

    public function test_mixed_equipment_count_binds_all_requested_kinds_and_disables_expensive_unrequested_fields(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_bim_model_elements');
        $properties = (new \App\BusinessModules\Features\AIAssistant\Actions\Domains\GetBimModelElementsTool(
            (new \ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelReader::class))->newInstanceWithoutConstructor()))->getParametersSchema()['properties'];
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => $properties, 'additionalProperties' => false]);
        $seen = [];
        $tool->expects($this->once())->method('execute')->willReturnCallback(static function (array $args) use (&$seen): array {
            $seen = $args;
            return ['status' => 'success'];
        });
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = $this->makeService($registry, true);
        $query = 'В Севгу сколько кондиционеров и вентиляционных установок?';
        $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve($query)->toArray(),
            'request' => ['message' => $query, 'context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $failures = [];
        $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'mixed', 'name' => 'get_bim_model_elements',
            'arguments' => json_encode(['project_id' => 52, 'model_query' => 'Севгу', 'equipment_kind' => 'air_conditioner',
                'element_query' => 'вентиляционные установки', 'equipment_kinds' => null, 'include_properties' => true,
                'include_quantities' => true, 'include_estimate_links' => true, 'answer_kind' => 'elements', 'offset' => 20, 'limit' => 20], JSON_THROW_ON_ERROR)], $plan, $failures);
        self::assertSame(['air_conditioner', 'ventilation_unit'], $seen['equipment_kinds'], json_encode($seen));
        self::assertNull($seen['equipment_kind']);
        self::assertNull($seen['element_query']);
        self::assertFalse($seen['include_properties']);
        self::assertFalse($seen['include_quantities']);
        self::assertFalse($seen['include_estimate_links']);
        self::assertSame(1, $seen['limit']);
        self::assertSame(0, $seen['offset']);
    }

    public function test_model_selected_domain_search_arguments_are_not_rewritten_from_classified_intent(): void
    {
        $arguments = ['domain' => 'contracts', 'entity_type' => 'contract', 'query' => 'Подрядчик', 'project_id' => null];
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('assistant_domain_search');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => [
            'domain' => ['type' => 'string'], 'entity_type' => ['type' => 'string'], 'query' => ['type' => 'string'],
            'project_id' => ['type' => ['integer', 'null']],
        ], 'required' => ['domain', 'entity_type', 'query'], 'additionalProperties' => false]);
        $tool->expects($this->once())->method('execute')->with($arguments, $this->anything(), $this->anything())->willReturn(['status' => 'success']);
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = $this->makeService($registry, true);
        $plan = ['request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve('Что с платежами?')->toArray(),
            'request' => ['context' => ['entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $failures = [];
        $result = $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'call_search', 'name' => 'assistant_domain_search',
            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR)], $plan, $failures);
        self::assertSame('success', $result['status']);
        self::assertSame([], $failures);
    }


    public function test_reports_capability_exposes_schedule_report_tools(): void
    {
        $toolRegistry = new AIToolRegistry;
        foreach ([
            'get_schedule_snapshot',
            'generate_project_timelines_report',
        ] as $toolName) {
            $toolRegistry->registerTool($this->makeTool($toolName));
        }

        $service = $this->makeService($toolRegistry);

        $tools = $service->exposeResolveToolDefinitions([
            'task_type' => 'summary',
            'capability' => [
                'id' => 'reports',
            ],
            'request_understanding' => (new AssistantRequestUnderstandingResolver)->resolve('Сделай отчет по графику работ')->toArray(),
            'request' => [
                'allow_actions' => false,
                'context' => [
                    'ui_state' => [
                        'assistant_report_focus' => 'schedules',
                    ],
                ],
            ],
        ]);

        $toolNames = array_map(
            static fn (array $definition): string => (string) $definition['name'],
            $tools
        );

        $this->assertContains('get_schedule_snapshot', $toolNames);
        $this->assertContains('generate_project_timelines_report', $toolNames);
    }

    public function test_request_policy_filters_report_tools_before_llm(): void
    {
        $toolRegistry = new AIToolRegistry;
        foreach ([
            'get_project_snapshot',
            'generate_operational_pdf_report',
        ] as $toolName) {
            $toolRegistry->registerTool($this->makeTool($toolName));
        }

        $service = $this->makeService($toolRegistry);

        $tools = $service->exposeResolveToolDefinitions([
            'task_type' => 'find',
            'capability' => [
                'id' => 'reports',
            ],
            'request' => [
                'allow_actions' => false,
                'context' => [],
            ],
            'request_understanding' => [
                'primary_intent' => 'search_knowledge',
                'output_format' => 'text',
                'action_policy' => 'read_only',
                'constraints' => ['no_file', 'no_pdf', 'no_report', 'text_only'],
                'requested_entities' => ['project'],
                'confidence' => 0.9,
                'evidence' => [],
            ],
        ]);

        $toolNames = array_map(
            static fn (array $definition): string => (string) $definition['name'],
            $tools
        );

        $this->assertContains('get_project_snapshot', $toolNames);
        $this->assertNotContains('generate_operational_pdf_report', $toolNames);
    }

    public function test_request_policy_blocks_forbidden_tool_call_before_execution(): void
    {
        $toolRegistry = new AIToolRegistry;
        $tool = new class implements AIToolInterface
        {
            public bool $executed = false;

            public function getName(): string
            {
                return 'generate_operational_pdf_report';
            }

            public function getDescription(): string
            {
                return 'Test report tool';
            }

            public function getParametersSchema(): array
            {
                return ['type' => 'object', 'properties' => ['report_type' => ['type' => 'string']], 'required' => ['report_type'], 'additionalProperties' => false];
            }

            public function execute(array $arguments, ?User $user, Organization $organization): array|string
            {
                $this->executed = true;

                return ['status' => 'success'];
            }
        };
        $toolRegistry->registerTool($tool);

        $service = $this->makeService($toolRegistry);
        $toolFailures = [];

        $result = $service->exposeHandleToolCall([
            'type' => 'function_call', 'call_id' => 'call_report',
            'name' => 'generate_operational_pdf_report',
            'arguments' => '{"report_type":"projects_summary"}',
        ], [
            'request_understanding' => [
                'primary_intent' => 'search_knowledge',
                'output_format' => 'text',
                'action_policy' => 'read_only',
                'constraints' => ['no_file', 'no_pdf', 'no_report', 'text_only'],
                'requested_entities' => ['project'],
                'confidence' => 0.9,
                'evidence' => [],
            ],
        ], $toolFailures);

        $this->assertSame('blocked_by_request_policy', $result['status']);
        $this->assertFalse($tool->executed);
        $this->assertNotSame([], $toolFailures);
    }

    public function test_tool_registry_resolves_legacy_schedule_status_alias(): void
    {
        $registry = new AIToolRegistry;
        $tool = $this->makeTool('update_schedule_task_status');

        $registry->registerTool($tool);

        $this->assertSame($tool, $registry->getTool('update_task_status'));
    }

    public function test_tool_exception_uses_safe_translation_without_internal_message(): void
    {
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('search_projects');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
        $tool->expects($this->once())->method('execute')->willThrowException(new \RuntimeException('secret database connection amount=999999'));
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = $this->makeService($registry, true);
        $logging = (new \ReflectionProperty(AIAssistantService::class, 'logging'))->getValue($service);
        $logging->expects($this->once())->method('technical')->with('ai.tool.error', [
            'tool' => 'search_projects', 'organization_id' => 15, 'user_id' => 7,
            'exception_class' => \RuntimeException::class, 'tool_failure_stage' => 'tool_execution',
        ], 'error');
        $failures = [];
        $result = $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'call_projects', 'name' => 'search_projects', 'arguments' => '{}'], [], $failures);
        $safe = trans_message('ai_assistant.tool_execute_failed');
        $this->assertSame(['error' => $safe], $result);
        $this->assertSame([$safe], $failures);
        $this->assertStringNotContainsString('secret database', json_encode([$result, $failures], JSON_THROW_ON_ERROR));
    }

    public function test_invalid_optional_contract_argument_is_diagnosed_before_execution_without_exposing_values(): void
    {
        $schema = (new \App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly\GetContractSnapshotTool)->getParametersSchema();
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn('get_contract_snapshot');
        $tool->method('getParametersSchema')->willReturn($schema);
        $tool->expects($this->never())->method('execute');
        $registry = new AIToolRegistry;
        $registry->registerTool($tool);
        $service = $this->makeService($registry, true);
        $logging = (new \ReflectionProperty(AIAssistantService::class, 'logging'))->getValue($service);
        $logging->expects($this->once())->method('technical')->with('ai.tool.error', [
            'tool' => 'get_contract_snapshot', 'organization_id' => 15, 'user_id' => 7,
            'exception_class' => \RuntimeException::class, 'tool_failure_stage' => 'argument_validation',
        ], 'error');
        $failures = [];
        $result = $service->exposeHandleToolCall(['name' => 'get_contract_snapshot',
            'arguments' => '{"project_id":52,"contract_id":null,"number":"private synthetic filter"}'], [], $failures);
        $safe = trans_message('ai_assistant.tool_execute_failed');
        self::assertSame(['error' => $safe], $result);
        self::assertSame([$safe], $failures);
        self::assertStringNotContainsString('private synthetic filter', json_encode([$result, $failures], JSON_THROW_ON_ERROR));
    }

    public function test_tool_failure_correlates_only_valid_uuid_from_active_request(): void
    {
        foreach (['eaa072f4-5694-4adf-a7af-b3e8a69b30a3', 'private invalid request value'] as $requestId) {
            $tool = $this->createMock(AIToolInterface::class);
            $tool->method('getName')->willReturn('search_projects');
            $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => []]);
            $tool->expects($this->never())->method('execute');
            $registry = new AIToolRegistry;
            $registry->registerTool($tool);
            $service = $this->makeService($registry);
            $request = new \App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
            $request->setRawAttributes(['request_id' => $requestId]);
            (new \ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($service, $request);
            $expected = ['tool' => 'search_projects', 'organization_id' => 15, 'user_id' => 7,
                'exception_class' => \RuntimeException::class, 'tool_failure_stage' => 'argument_validation'];
            if (\Illuminate\Support\Str::isUuid($requestId)) {
                $expected['request_id'] = $requestId;
            }
            $logging = (new \ReflectionProperty(AIAssistantService::class, 'logging'))->getValue($service);
            $logging->expects($this->once())->method('technical')->with('ai.tool.error', $expected, 'error');
            $failures = [];
            $result = $service->exposeHandleToolCall(['name' => 'search_projects',
                'arguments' => '{"unknown":"private filter"}'], [], $failures);
            self::assertSame(['error' => trans_message('ai_assistant.tool_execute_failed')], $result);
        }
    }

    public function test_follow_up_payload_keeps_previous_schedule_report_intent(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $payload = $service->exposeMergeContinuationRequestPayload(
            'с 1.04.2026 по 01.05.2026 по текущему проекту',
            [
                'context' => [
                    'source_module' => 'ai-assistant',
                    'entity_refs' => [
                        [
                            'type' => 'project',
                            'id' => 56,
                            'label' => 'Строительство склада Литер А',
                        ],
                    ],
                ],
            ],
            [
                'last_task_type' => 'summary',
                'last_capability' => 'reports',
                'last_request' => [
                    'message' => 'Сделай отчет по графику работ',
                    'context' => [
                        'source_module' => 'reports',
                        'source_route' => '/reports',
                        'entity_refs' => [],
                        'period' => null,
                        'filters' => [],
                        'ui_state' => [],
                    ],
                ],
                'last_report_focus' => 'schedules',
            ]
        );

        $this->assertSame('reports', $payload['context']['source_module']);
        $this->assertSame('/reports', $payload['context']['source_route']);
        $this->assertSame('schedules', $payload['context']['ui_state']['assistant_report_focus']);
        $this->assertSame('summary', $payload['desired_mode']);
    }

    public function test_follow_up_payload_accepts_relative_period_without_project_context(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $payload = $service->exposeMergeContinuationRequestPayload(
            'За 2 месяца',
            [
                'context' => [
                    'source_module' => 'ai-assistant',
                ],
            ],
            [
                'last_task_type' => 'summary',
                'last_capability' => 'reports',
                'last_request' => [
                    'message' => 'Сделай отчет по графику работ',
                    'context' => [
                        'source_module' => 'reports',
                        'source_route' => '/reports',
                        'entity_refs' => [
                            [
                                'type' => 'project',
                                'id' => 56,
                                'label' => 'Строительство склада Литер А',
                            ],
                        ],
                        'period' => null,
                        'filters' => [],
                        'ui_state' => [],
                    ],
                ],
                'last_report_focus' => 'schedules',
            ]
        );

        $this->assertSame('reports', $payload['context']['source_module']);
        $this->assertSame('/reports', $payload['context']['source_route']);
        $this->assertSame('За 2 месяца', $payload['context']['period']);
        $this->assertSame(56, $payload['context']['entity_refs'][0]['id']);
    }

    public function test_follow_up_payload_keeps_relative_period_when_project_context_is_present(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $payload = $service->exposeMergeContinuationRequestPayload(
            'За 2 месяца',
            [
                'context' => [
                    'source_module' => 'ai-assistant',
                    'entity_refs' => [
                        [
                            'type' => 'project',
                            'id' => 56,
                        ],
                    ],
                ],
            ],
            [
                'last_task_type' => 'summary',
                'last_capability' => 'reports',
                'last_request' => [
                    'message' => 'Сделай отчет по графику работ',
                    'context' => [
                        'source_module' => 'reports',
                        'source_route' => '/reports',
                        'entity_refs' => [],
                        'period' => null,
                        'filters' => [],
                        'ui_state' => [],
                    ],
                ],
                'last_report_focus' => 'schedules',
            ]
        );

        $this->assertSame('За 2 месяца', $payload['context']['period']);
        $this->assertSame(56, $payload['context']['entity_refs'][0]['id']);
    }

    public function test_follow_up_payload_passes_short_period_reply_to_model_context(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $payload = $service->exposeMergeContinuationRequestPayload(
            'за ноябрь',
            [
                'context' => [
                    'source_module' => 'ai-assistant',
                ],
            ],
            [
                'last_task_type' => 'summary',
                'last_capability' => 'reports',
                'last_request' => [
                    'message' => 'Сделай отчет по графику работ',
                    'context' => [
                        'source_module' => 'reports',
                        'source_route' => '/reports',
                        'entity_refs' => [],
                        'period' => null,
                        'filters' => [],
                        'ui_state' => [],
                    ],
                ],
                'last_report_focus' => 'schedules',
            ]
        );

        $this->assertSame('reports', $payload['context']['source_module']);
        $this->assertSame('за ноябрь', $payload['context']['period']);
    }

    public function test_new_read_request_does_not_inherit_report_mode_period_or_entity(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $request = ['context' => ['source_module' => 'ai-assistant', 'entity_refs' => [['type' => 'project', 'id' => 56]]]];
        $previous = [
            'last_task_type' => 'analyze',
            'last_capability' => 'reports',
            'last_request' => ['goal' => 'analyze', 'context' => [
                'source_module' => 'reports', 'period' => 'за год',
                'entity_refs' => [['type' => 'estimate', 'id' => 7]],
                'ui_state' => ['assistant_report_focus' => 'payments'],
            ]],
        ];
        foreach (['Покажи доступные договоры и контрагентов.', 'Посмотри наши договоры.', 'Расскажи об объёме бетона в BIM-модели.'] as $query) {
            self::assertSame($request, $service->exposeMergeContinuationRequestPayload($query, $request, $previous), $query);
        }
    }

    public function test_read_command_with_only_a_period_continues_the_previous_report(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $request = ['context' => ['source_module' => 'ai-assistant']];
        $previous = [
            'last_task_type' => 'summary', 'last_capability' => 'reports',
            'last_request' => ['context' => [
                'source_module' => 'reports', 'period' => null,
                'entity_refs' => [['type' => 'project', 'id' => 56]],
            ]],
        ];
        foreach (['Покажи за ноябрь', 'Посмотри за ноябрь', 'Покажи его'] as $query) {
            $payload = $service->exposeMergeContinuationRequestPayload($query, $request, $previous);
            self::assertSame('reports', $payload['context']['source_module']);
            self::assertSame(56, $payload['context']['entity_refs'][0]['id']);
            self::assertSame('summary', $payload['desired_mode']);
        }
    }

    public function test_detail_follow_up_keeps_previous_rag_topic_and_project_context(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $payload = $service->exposeMergeContinuationRequestPayload(
            'Давай подробнее',
            [
                'context' => [
                    'source_module' => 'ai-assistant',
                ],
            ],
            [
                'last_task_type' => 'summary',
                'last_capability' => null,
                'last_request' => [
                    'message' => 'Что есть по бетонированию в смете?',
                    'context' => [
                        'source_module' => 'projects',
                        'source_route' => '/projects/56',
                        'entity_refs' => [
                            [
                                'type' => 'project',
                                'id' => 56,
                                'label' => 'Строительство склада Литер А',
                            ],
                        ],
                        'period' => null,
                        'filters' => [],
                        'ui_state' => [],
                    ],
                ],
                'last_rag_context' => [
                    'query' => 'Что есть по бетонированию в смете?',
                    'sources' => [
                        [
                            'title' => 'Раздел сметы: Фундамент',
                            'excerpt' => 'Бетонирование 115 кубических метров на сумму 115 000 рублей.',
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame('projects', $payload['context']['source_module']);
        $this->assertSame('/projects/56', $payload['context']['source_route']);
        $this->assertSame(56, $payload['context']['entity_refs'][0]['id']);
        $this->assertSame('summary', $payload['desired_mode']);
        $this->assertNull($payload['context']['period'] ?? null);
        $this->assertStringContainsString('Что есть по бетонированию', $payload['context']['ui_state']['assistant_follow_up_query']);
        $this->assertStringContainsString('Бетонирование 115 кубических метров', $payload['context']['ui_state']['assistant_follow_up_query']);
    }

    public function test_rag_search_query_uses_detail_follow_up_context(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $query = $service->exposeResolveRagSearchQuery('Давай подробнее', [
            'context' => [
                'ui_state' => [
                    'assistant_follow_up_query' => "Давай подробнее\nПредыдущий запрос: Что есть по бетонированию?",
                ],
            ],
        ]);

        $this->assertSame('Давай подробнее Предыдущий запрос: Что есть по бетонированию?', $query);
    }

    public function test_compact_rag_context_keeps_expanded_follow_up_search_query(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $context = $service->exposeCompactRagContextForContinuation([
            'used' => true,
            'query' => 'Давай подробнее',
            'search_query' => 'Давай подробнее Предыдущий запрос: Что есть по бетонированию?',
            'sources' => [
                [
                    'source_type' => 'estimate',
                    'entity_type' => 'estimate_section',
                    'entity_id' => 11,
                    'project_id' => 56,
                    'title' => 'Раздел сметы: Фундамент',
                    'excerpt' => 'Бетонирование 115 кубических метров на сумму 115 000 рублей.',
                ],
            ],
        ]);

        $this->assertNotNull($context);
        $this->assertSame(
            'Давай подробнее Предыдущий запрос: Что есть по бетонированию?',
            $context['query']
        );
        $this->assertSame('estimate_section', $context['sources'][0]['entity_type']);
        $this->assertSame(56, $context['sources'][0]['project_id']);
    }

    public function test_provider_budget_preserves_mandatory_instruction_and_full_query(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $messages = [
            [
                'role' => 'system',
                'content' => str_repeat('system-context ', 500),
            ],
            [
                'role' => 'assistant',
                'content' => str_repeat('long assistant answer ', 700),
            ],
            [
                'role' => 'user',
                'content' => str_repeat('старый длинный запрос ', 600),
            ],
            [
                'role' => 'assistant',
                'content' => str_repeat('длинная сводка ', 600),
            ],
            [
                'role' => 'user',
                'content' => str_repeat('я', 4000),
            ],
        ];

        [$preparedMessages, $options] = $service->exposePrepareProviderPayload($messages, ['budget_profile' => 'normal']);

        $this->assertNotEmpty($preparedMessages);
        $this->assertSame('system', $preparedMessages[0]['role']);
        $this->assertLessThanOrEqual(16384, $options['estimated_input_tokens']);
        $this->assertSame(2048, $options['max_completion_tokens']);
        $this->assertSame($messages[0]['content'], $preparedMessages[0]['content']);
        $this->assertSame('user', $preparedMessages[array_key_last($preparedMessages)]['role']);
        $this->assertSame(str_repeat('я', 4000), $preparedMessages[array_key_last($preparedMessages)]['content']);
    }

    public function test_native_tool_catalog_does_not_displace_authorized_conversation_history(): void
    {
        $query = 'Какое условное число я назвал в предыдущем сообщении?';
        $history = [['role' => 'user', 'content' => 'Условный объём бетона — 12 м³.']];
        $service = $this->makeService(new AIToolRegistry, history: $history);
        (new \ReflectionProperty(AIAssistantService::class, 'precomputedCapabilityHints'))->setValue($service, ['domains' => str_repeat('каталог инструментов ', 1600)]);
        $conversation = new \App\BusinessModules\Features\AIAssistant\Models\Conversation;
        $plan = ['task_type' => 'summary', 'request' => ['context' => ['source_module' => 'projects', 'entity_refs' => [['type' => 'project', 'id' => 52]]]]];
        $messages = $service->exposeBuildMessages($conversation, $plan, $query);
        [$prepared] = $service->exposePrepareProviderPayload($messages, ['budget_profile' => 'normal']);
        self::assertContains($history[0], $prepared);
        self::assertSame($messages[0], $prepared[0]);
        self::assertSame($messages[count($messages) - 2], $prepared[count($prepared) - 2]);
        self::assertSame($query, $prepared[array_key_last($prepared)]['content']);
    }

    public function test_general_clarification_does_not_require_a_complete_document_corpus(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        self::assertFalse($service->exposeDocumentEvidenceRequired('Что нужно уточнить для расчёта доставки?'));
        self::assertFalse($service->exposeDocumentEvidenceRequired('Какие параметры нужны для расчёта?'));
        self::assertTrue($service->exposeDocumentEvidenceRequired('Что нужно уточнить по условиям моего договора?'));
        self::assertTrue($service->exposeDocumentEvidenceRequired('Покажи весь текст договора.'));
        self::assertTrue($service->exposeDocumentEvidenceRequired('Покажи текущие суммы проекта.'));
    }

    public function test_mandatory_context_overflow_fails_without_hidden_fallback_or_truncation(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $query = str_repeat('я', 4000);
        $messages = [['role' => 'system', 'content' => str_repeat('я', 40000)], ['role' => 'user', 'content' => $query]];
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('ai_token_budget_exhausted');
        $service->exposePrepareProviderPayload($messages, ['budget_profile' => 'short']);
    }

    public function test_untrusted_report_markdown_link_is_rendered_as_text(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $content = 'Готово! [Скачать отчет](реальный_pdf_url_из_данных)';

        $this->assertSame('Готово! Скачать отчет', $service->exposeStripUntrustedMarkdownLinks($content));
    }

    public function test_trusted_report_markdown_link_is_preserved(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $url = 'https://s3.twcstorage.ru/prohelper-storage/org-1/reports/report.pdf?X-Amz-Signature=test';
        $content = "Готово! [Скачать отчет]({$url})";

        $this->assertSame($content, $service->exposeStripUntrustedMarkdownLinks($content, [$url]));
    }

    public function test_report_completion_without_trusted_download_url_is_replaced(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $content = $service->exposeGuardUnconfirmedReportCompletion(
            'Готово! Отчет по графику работ готов. Скачать отчет',
            [
                'task_type' => 'summary',
                'capability' => [
                    'id' => 'reports',
                ],
                'request' => [
                    'context' => [
                        'source_module' => 'reports',
                    ],
                ],
            ],
            []
        );

        $this->assertSame(
            'Не удалось сформировать файл отчета по текущему запросу. Попробуйте повторить запрос или уточнить период и проект.',
            $content
        );
    }

    public function test_report_completion_with_trusted_download_url_is_preserved(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $content = 'Готово! Отчет по графику работ готов. [Скачать отчет](https://example.test/report.pdf)';

        $this->assertSame(
            $content,
            $service->exposeGuardUnconfirmedReportCompletion(
                $content,
                [
                    'task_type' => 'summary',
                    'capability' => [
                        'id' => 'reports',
                    ],
                    'request' => [
                        'context' => [
                            'source_module' => 'reports',
                        ],
                    ],
                ],
                ['https://example.test/report.pdf']
            )
        );
    }

    public function test_structured_context_policy_is_single_utf8_path(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $context = $service->exposeFormatStructuredContextForLLM([
            'task_type' => 'summary',
            'capability' => [
                'label' => 'Графики',
            ],
            'request' => [
                'goal' => null,
                'desired_mode' => null,
                'allow_actions' => false,
                'context' => [
                    'source_module' => 'schedules',
                    'entity_refs' => [],
                    'period' => null,
                    'filters' => [],
                    'ui_state' => [],
                ],
            ],
            'access_context_public' => [
                'available_modules' => ['schedules'],
                'permission_count' => 1,
                'is_read_only' => true,
                'allowed_action_types' => ['summary', 'find', 'analyze', 'navigate'],
            ],
            'navigation_target' => null,
            'next_actions' => [],
        ]);

        $this->assertStringContainsString('Опирайся только на подтвержденные данные', $context);
        $this->assertSame(1, substr_count($context, '=== STRUCTURED WORKSPACE CONTEXT ==='));
        $this->assertStringNotContainsString("\u{0420}\u{045F}", $context);
    }

    public function test_structured_context_includes_current_runtime_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-02 22:54:00', 'Europe/Moscow'));

        try {
            $service = $this->makeService(new AIToolRegistry);

            $context = $service->exposeFormatStructuredContextForLLM([
                'task_type' => 'summary',
                'capability' => [
                    'label' => 'Графики',
                ],
                'request' => [
                    'goal' => null,
                    'desired_mode' => null,
                    'allow_actions' => false,
                    'context' => [
                        'source_module' => 'ai-assistant',
                        'entity_refs' => [],
                        'period' => null,
                        'filters' => [],
                        'ui_state' => [],
                    ],
                ],
                'access_context_public' => [
                    'available_modules' => ['ai-assistant'],
                    'permission_count' => 1,
                    'is_read_only' => true,
                    'allowed_action_types' => ['summary'],
                ],
                'navigation_target' => null,
                'next_actions' => [],
            ]);

            $this->assertStringContainsString('runtime:', $context);
            $this->assertStringContainsString('current_date: 2026-07-02', $context);
            $this->assertStringContainsString('current_date_ru: 02.07.2026', $context);
            $this->assertStringContainsString('current_date_human: 2 июля 2026', $context);
            $this->assertStringContainsString('current_weekday: четверг', $context);
            $this->assertStringContainsString('timezone: Europe/Moscow', $context);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rag_context_is_safely_unused_without_retriever(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $user = new User;
        $user->id = 7;
        $user->current_organization_id = 15;

        $context = $service->exposeBuildRagContext(
            'Что тормозит проект?',
            15,
            $user,
            [
                'request' => [
                    'context' => [
                        'entity_refs' => [
                            [
                                'type' => 'project',
                                'id' => 56,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'context' => [
                    'source_module' => 'projects',
                ],
            ]
        );

        $this->assertSame('', $context['prompt']);
        $this->assertFalse($context['metadata']['used']);
        $this->assertSame([], $context['metadata']['sources']);
    }

    public function test_standalone_greeting_skips_rag_search_but_contextual_greeting_does_not(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $user = new User;
        $user->id = 7;
        $user->current_organization_id = 15;

        $context = $service->exposeBuildRagContext('Привет', 15, $user, [], [
            'conversation_id' => null,
            'context' => [
                'source_module' => 'ai-assistant',
                'entity_refs' => [['type' => 'project', 'id' => 56, 'label' => 'Текущий проект']],
                'ui_state' => ['assistant_path' => '/ai-assistant/chat'],
            ],
        ]);
        $this->assertFalse($service->ragQueryResolved);
        $this->assertFalse($context['metadata']['used']);

        $service->exposeBuildRagContext('Привет', 15, $user, [], [
            'conversation_id' => 24,
            'context' => ['source_module' => 'ai-assistant', 'entity_refs' => [['type' => 'project', 'id' => 56]], 'ui_state' => []],
        ]);
        $this->assertTrue($service->ragQueryResolved);

        $service->ragQueryResolved = false;
        $service->exposeBuildRagContext('Привет', 15, $user, [], [
            'conversation_id' => null,
            'context' => ['source_module' => 'ai-assistant', 'entity_refs' => [['type' => 'material', 'id' => 56]], 'ui_state' => []],
        ]);
        $this->assertTrue($service->ragQueryResolved);
    }

    public function test_service_requests_native_items_and_never_chat_with_replay_guard(): void
    {
        $call = ['type' => 'function_call', 'id' => 'fc_service', 'status' => 'completed', 'call_id' => 'call_service',
            'name' => 'search_projects', 'arguments' => '{}'];
        $provider = $this->createMock(NativeResponsesTestProvider::class);
        $provider->expects($this->never())->method('chat');
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->expects($this->exactly(2))->method('responses')->willReturnCallback(function (array $input, array $options) use ($call): array {
            self::assertInstanceOf(\App\Support\AI\PreparedTokenBudget::class, $options['_prepared_token_budget']);
            self::assertSame('function', $options['tools'][0]['type']);
            self::assertArrayNotHasKey('function', $options['tools'][0]);
            self::assertSame('Запрос', $input[0]['content']);
            return ['content' => '', 'output' => [$call], 'function_calls' => [$call], 'response_status' => 'completed',
                'model' => 'openai/gpt-6-luna', 'input_tokens' => 10, 'output_tokens' => 5, 'tokens_used' => 15];
        });
        $registry = new AIToolRegistry;
        $registry->registerTool($this->makeTool('search_projects'));
        $service = $this->makeService($registry, provider: $provider);
        $tools = $registry->getToolsDefinitions(['search_projects'], true);
        $input = [['role' => 'user', 'content' => 'Запрос']];
        $first = $service->exposeRequestAssistantResponse($input, ['tools' => $tools]);
        self::assertSame([$call], $first['response']['function_calls']);
        $input = [...$input, ...$first['response']['output'], ['type' => 'function_call_output', 'call_id' => 'call_service', 'output' => '{"found":2}']];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assistant_native_replayed_output');
        $service->exposeRequestAssistantResponse($input, ['tools' => $tools]);
    }

    public function test_native_preparation_preserves_full_output_and_rejects_legacy_tool_input(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $items = [['role' => 'user', 'content' => 'Запрос'],
            ['type' => 'message', 'id' => 'msg_native', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'Проверю', 'annotations' => []]]],
            ['type' => 'function_call', 'id' => 'fc_native', 'status' => 'completed', 'call_id' => 'same_call', 'name' => 'lookup', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'same_call', 'output' => '{"count":2}']];
        [$prepared] = $service->exposePrepareProviderPayload($items, []);
        self::assertSame($items, $prepared);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assistant_native_input_required');
        $service->exposePrepareMessagesForProvider([['role' => 'tool', 'content' => 'legacy']]);
    }

    public function test_server_result_metadata_does_not_claim_configured_model_as_response_evidence(): void
    {
        $provider = $this->createMock(NativeResponsesTestProvider::class);
        $provider->method('getModel')->willReturn('openai/gpt-6-luna');
        $provider->expects($this->never())->method('responses');
        $service = $this->makeService(new AIToolRegistry, provider: $provider);
        $actor = new User;
        $actor->id = 7;
        $metadata = (new \ReflectionMethod(AIAssistantService::class, 'decorateMetadata'))->invoke($service, ['source_refs' => []], $actor);
        self::assertNull($metadata['actual_model']);
        self::assertNull($metadata['provider_response_ref']);
        self::assertNull($metadata['api_method']);
        self::assertFalse($metadata['model_invoked']);
    }

    public function test_summary_cache_access_rejection_does_not_fail_current_answer_publication(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects(self::once())->method('saveSummary')->willThrowException(new \RuntimeException('conversation_summary_access_denied'));
        $this->invokePendingSummary($service);
        self::assertNull((new \ReflectionProperty(AIAssistantService::class, 'pendingSummary'))->getValue($service));
    }

    public function test_summary_cache_version_conflict_does_not_fail_current_answer_publication(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects(self::once())->method('saveSummary')->willThrowException(new \RuntimeException('conversation_context_version_conflict'));
        $this->invokePendingSummary($service);
        self::assertNull((new \ReflectionProperty(AIAssistantService::class, 'pendingSummary'))->getValue($service));
    }

    public function test_summary_conversation_access_denial_still_aborts_publication(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects(self::once())->method('saveSummary')->willThrowException(new \RuntimeException('conversation_access_denied'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conversation_access_denied');
        $this->invokePendingSummary($service);
    }

    public function test_summary_rejection_with_stale_current_answer_references_still_aborts_publication(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects(self::never())->method('saveSummary');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assistant_response_sources_stale');
        $this->invokePendingSummary($service, false);
    }

    public function test_partial_answer_with_stale_references_still_aborts_publication(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects(self::never())->method('saveSummary');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('assistant_response_sources_stale');
        $this->invokePendingSummary($service, false, 'partial');
    }

    public function test_partial_answer_with_fresh_references_does_not_create_verified_summary(): void
    {
        $service = $this->makeService(new AIToolRegistry);
        $manager = (new \ReflectionProperty(AIAssistantService::class, 'conversationManager'))->getValue($service);
        $manager->expects(self::never())->method('saveSummary');
        $this->invokePendingSummary($service, true, 'partial');
        self::assertNull((new \ReflectionProperty(AIAssistantService::class, 'pendingSummary'))->getValue($service));
    }

    private function invokePendingSummary(TestableAIAssistantService $service, bool $fresh = true, string $status = 'verified'): void
    {
        $guard = $this->createMock(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class);
        $guard->method('fresh')->willReturn($fresh);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard::class, $guard);
        $conversation = $this->getMockBuilder(\App\BusinessModules\Features\AIAssistant\Models\Conversation::class)->onlyMethods(['refresh'])->getMock();
        $conversation->method('refresh')->willReturnSelf();
        $conversation->setRawAttributes(['id' => 207, 'organization_id' => 15, 'context_version' => 4]);
        (new \ReflectionProperty(AIAssistantService::class, 'pendingSummary'))->setValue($service, [
            'conversation' => $conversation, 'summary' => 'Verified current request', 'selected_entities' => [], 'validation_status' => $status,
            'user_decisions' => [], 'source_refs' => [['entity_type' => 'contract', 'entity_id' => 8, 'fetched_at' => '2026-10-11T18:55:00Z']],
        ]);
        (new \ReflectionMethod(AIAssistantService::class, 'savePendingSummary'))->invoke($service, new User(['id' => 9, 'current_organization_id' => 15]));
    }

    private function makeService(AIToolRegistry $toolRegistry, bool $canExecute = false, ?LLMProviderInterface $provider = null, array $history = []): TestableAIAssistantService
    {
        $llmProvider = $provider ?? $this->createMock(NativeResponsesTestProvider::class);
        $conversationManager = $this->createMock(ConversationManager::class);
        $conversationManager->method('getMessagesForContextWithBudget')->willReturn($history);
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $intentRecognizer = $this->createMock(IntentRecognizer::class);
        $usageTracker = $this->createMock(UsageTracker::class);
        $logging = $this->createMock(LoggingService::class);
        $permissionChecker = $this->createMock(AIPermissionChecker::class);
        $permissionChecker->method('canExecuteTool')->willReturn($canExecute);
        $accessContextResolver = $this->createMock(AssistantAccessContextResolver::class);
        $taskOrchestrator = $this->createMock(AssistantTaskOrchestrator::class);

        return new TestableAIAssistantService(
            $llmProvider,
            $conversationManager,
            $contextBuilder,
            $intentRecognizer,
            $usageTracker,
            $logging,
            $toolRegistry,
            $permissionChecker,
            $accessContextResolver,
            $taskOrchestrator,
            new AssistantAgentStateStore,
            new AssistantAgentPlanner(new AssistantCapabilityCatalog, new AssistantPeriodResolver),
            new AssistantAgentExecutor($toolRegistry, $permissionChecker, new AssistantArtifactNormalizer),
            new AssistantResponseVerifier,
            tokenBudget: new \App\Support\AI\TokenBudgetService(new \App\Support\AI\TokenCounter(new class {
                public function encode(string $text): array { return array_fill(0, mb_strlen($text), 1); }
            }))
        );
    }

    private function makeTool(string $name): AIToolInterface
    {
        return new class($name) implements AIToolInterface
        {
            public function __construct(
                private readonly string $name
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'Test tool';
            }

            public function getParametersSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                        ],
                    ],
                ];
            }

            public function execute(array $arguments, ?User $user, Organization $organization): array|string
            {
                return ['status' => 'ok'];
            }
        };
    }
}

class TestableAIAssistantService extends AIAssistantService
{
    public array|string|null $freshProjectSearch = null;
    public array $freshProjectSearchCalls = [];
    public array $bimAliases = [];
    public ?array $bimAliasAnswer = null;
    public array $bimFallbackCalls = [];

    protected function scopedBimAliasMatches(User $actor, int $organizationId, int $projectId, string $alias): bool
    {
        return in_array($alias, $this->bimAliases[$projectId] ?? [], true);
    }

    protected function readBimAliasFallback(string $question, string $alias, User $actor, int $organizationId, int $projectId): ?array
    {
        $this->bimFallbackCalls[] = [$alias, $projectId];
        return $this->bimAliasAnswer;
    }

    protected function readFreshProjectSearch(string $name, User $actor, int $organizationId): array|string|null
    {
        $this->freshProjectSearchCalls[] = [$name, $organizationId];

        return $this->freshProjectSearch;
    }

    public function exposeVerifyEmptyNamedProjectSearch(string $question, array $results, User $actor, int $organizationId, ?int $projectId = null): ?array
    {
        return $this->verifyEmptyNamedProjectSearch($question, $results, $actor, $organizationId, $projectId);
    }
    public bool $ragQueryResolved = false;
    public array $canonicalBimProjects = [];

    protected function readableBimProjectName(User $actor, int $organizationId, int $projectId): ?string
    {
        return $this->canonicalBimProjects[$projectId] ?? null;
    }

    protected function resolveRagSearchQuery(string $query, array $requestPayload): string
    {
        $this->ragQueryResolved = true;

        return parent::resolveRagSearchQuery($query, $requestPayload);
    }

    public function exposeRequestAssistantResponse(array $input, array $options): array
    {
        $actor = new User;
        $actor->id = 7;
        return $this->requestAssistantResponse($input, $options, 15, $actor);
    }

    public function exposePrepareProviderPayload(array $messages, array $options): array
    {
        return $this->prepareProviderPayload($messages, $options, 15, new User);
    }

    public function exposeBuildMessages(\App\BusinessModules\Features\AIAssistant\Models\Conversation $conversation, array $plan, string $query): array
    {
        return $this->buildMessages($conversation, [], $plan, currentQuery: $query);
    }

    public function exposeDocumentEvidenceRequired(string $query): bool
    {
        return $this->documentEvidenceRequiredForAnswer($query);
    }

    public function exposeResolveToolDefinitions(array $taskPlan): array
    {
        return $this->resolveToolDefinitions($taskPlan);
    }

    public function exposePrepareMessagesForProvider(array $messages): array
    {
        return $this->prepareMessagesForProvider($messages);
    }

    public function exposeEstimateProviderInputTokens(array $messages, array $options): int
    {
        return $this->estimateProviderInputTokens($messages, $options);
    }

    public function exposeFormatStructuredContextForLLM(array $taskPlan): string
    {
        return $this->formatStructuredContextForLLM($taskPlan);
    }

    public function exposeMergeContinuationRequestPayload(
        string $query,
        array $requestPayload,
        array $conversationContext
    ): array {
        return $this->mergeContinuationRequestPayload($query, $requestPayload, $conversationContext);
    }

    public function exposeResolveRagSearchQuery(string $query, array $requestPayload): string
    {
        return $this->resolveRagSearchQuery($query, $requestPayload);
    }

    public function exposeCompactRagContextForContinuation(array $ragMetadata): ?array
    {
        return $this->compactRagContextForContinuation($ragMetadata);
    }

    public function exposeStripUntrustedMarkdownLinks(string $content, array $trustedUrls = []): string
    {
        return $this->stripUntrustedMarkdownLinks($content, $trustedUrls);
    }

    public function exposeGuardUnconfirmedReportCompletion(
        string $content,
        array $taskPlan,
        array $trustedUrls = []
    ): string {
        return $this->guardUnconfirmedReportCompletion($content, $taskPlan, $trustedUrls);
    }

    public function exposeBuildRagContext(
        string $query,
        int $organizationId,
        User $user,
        array $taskPlan,
        array $requestPayload
    ): array {
        return $this->buildRagContext($query, $organizationId, $user, $taskPlan, $requestPayload);
    }

    public function exposeHandleToolCall(array $toolCall, array $taskPlan, array &$toolFailures): array|string
    {
        $executedAction = null;
        $toolEvidence = [];
        $proposedActions = [];
        $trustedDownloadUrls = [];
        $organization = new Organization;
        $organization->id = 15;
        $user = new User;
        $user->id = 7;
        $user->current_organization_id = 15;

        return $this->handleToolCall(
            $toolCall,
            $organization,
            $user,
            15,
            $taskPlan,
            false,
            $executedAction,
            $toolEvidence,
            $toolFailures,
            $proposedActions,
            $trustedDownloadUrls
        );
    }
}
