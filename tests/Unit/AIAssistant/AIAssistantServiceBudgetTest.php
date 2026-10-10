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
        $failures = [];
        $result = $service->exposeHandleToolCall(['type' => 'function_call', 'call_id' => 'call_projects', 'name' => 'search_projects', 'arguments' => '{}'], [], $failures);
        $safe = trans_message('ai_assistant.tool_execute_failed');
        $this->assertSame(['error' => $safe], $result);
        $this->assertSame([$safe], $failures);
        $this->assertStringNotContainsString('secret database', json_encode([$result, $failures], JSON_THROW_ON_ERROR));
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
    public bool $ragQueryResolved = false;

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
