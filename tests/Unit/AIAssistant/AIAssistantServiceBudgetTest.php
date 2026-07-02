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

class AIAssistantServiceBudgetTest extends TestCase
{
    public function test_generic_summary_request_skips_tool_definitions(): void
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

        $this->assertSame([], $tools);
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
            static fn (array $definition): string => (string) $definition['function']['name'],
            $tools
        );

        $this->assertContains('get_procurement_snapshot', $toolNames);
        $this->assertContains('get_project_snapshot', $toolNames);
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
            static fn (array $definition): string => (string) $definition['function']['name'],
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
            static fn (array $definition): string => (string) $definition['function']['name'],
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
                return ['type' => 'object'];
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
            'id' => 'call_report',
            'function' => [
                'name' => 'generate_operational_pdf_report',
                'arguments' => '{"report_type":"projects_summary"}',
            ],
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

    public function test_prepare_messages_for_provider_preserves_latest_user_message_and_reduces_budget(): void
    {
        $service = $this->makeService(new AIToolRegistry);

        $messages = [
            [
                'role' => 'system',
                'content' => str_repeat('system-context ', 900),
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
                'content' => 'Найди аптечку на объекте Дом 300м Царево',
            ],
        ];

        $preparedMessages = $service->exposePrepareMessagesForProvider($messages);
        $estimatedTokens = $service->exposeEstimateProviderInputTokens($preparedMessages, []);

        $this->assertNotEmpty($preparedMessages);
        $this->assertSame('system', $preparedMessages[0]['role']);
        $this->assertLessThanOrEqual(12000, $estimatedTokens);
        $this->assertSame('user', $preparedMessages[array_key_last($preparedMessages)]['role']);
        $this->assertStringContainsString('Найди аптечку', $preparedMessages[array_key_last($preparedMessages)]['content']);
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
        $url = 'https://storage.yandexcloud.net/prohelper/reports/report.pdf?X-Amz-Signature=test';
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

    private function makeService(AIToolRegistry $toolRegistry): TestableAIAssistantService
    {
        $llmProvider = $this->createMock(LLMProviderInterface::class);
        $conversationManager = $this->createMock(ConversationManager::class);
        $contextBuilder = $this->createMock(ContextBuilder::class);
        $intentRecognizer = $this->createMock(IntentRecognizer::class);
        $usageTracker = $this->createMock(UsageTracker::class);
        $logging = $this->createMock(LoggingService::class);
        $permissionChecker = $this->createMock(AIPermissionChecker::class);
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
            new AssistantResponseVerifier
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
