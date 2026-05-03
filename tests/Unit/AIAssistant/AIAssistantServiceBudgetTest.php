<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
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
use PHPUnit\Framework\TestCase;

class AIAssistantServiceBudgetTest extends TestCase
{
    public function test_generic_summary_request_skips_tool_definitions(): void
    {
        $toolRegistry = new AIToolRegistry();
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
        $toolRegistry = new AIToolRegistry();
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
        $toolRegistry = new AIToolRegistry();
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

    public function test_tool_registry_resolves_legacy_schedule_status_alias(): void
    {
        $registry = new AIToolRegistry();
        $tool = $this->makeTool('update_schedule_task_status');

        $registry->registerTool($tool);

        $this->assertSame($tool, $registry->getTool('update_task_status'));
    }

    public function test_follow_up_payload_keeps_previous_schedule_report_intent(): void
    {
        $service = $this->makeService(new AIToolRegistry());

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

    public function test_prepare_messages_for_provider_preserves_latest_user_message_and_reduces_budget(): void
    {
        $service = $this->makeService(new AIToolRegistry());

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

    public function test_structured_context_policy_is_single_utf8_path(): void
    {
        $service = $this->makeService(new AIToolRegistry());

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
        $this->assertStringNotContainsString('Рџ', $context);
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
            $taskOrchestrator
        );
    }

    private function makeTool(string $name): AIToolInterface
    {
        return new class ($name) implements AIToolInterface {
            public function __construct(
                private readonly string $name
            ) {
            }

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
}
