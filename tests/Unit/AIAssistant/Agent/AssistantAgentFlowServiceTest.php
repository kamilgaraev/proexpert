<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskSlot;
use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
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
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class AssistantAgentFlowServiceTest extends TestCase
{
    public function test_missing_period_persists_clarification_and_waiting_agent_state(): void
    {
        $conversation = new AgentFlowConversation(101);
        $conversationManager = new AgentFlowConversationManager($conversation);
        $toolRegistry = new AIToolRegistry;

        $service = $this->makeService($conversationManager, $toolRegistry);

        $result = $service->ask(
            'Сделай отчет по графику работ',
            15,
            $this->makeUser(),
            null,
            $this->projectRequestPayload()
        );

        $assistantMessage = $conversationManager->assistantMessages()[0];

        $this->assertSame(101, $result['conversation_id']);
        $this->assertSame(0, $result['tokens_used']);
        $this->assertSame('assistant', $assistantMessage->role);
        $this->assertSame('report.project_timelines', $assistantMessage->metadata['agent_state']['id']);
        $this->assertSame('waiting_for_slots', $assistantMessage->metadata['agent_state']['status']);
        $this->assertContains('period', $assistantMessage->metadata['missing_data']);
        $this->assertSame('report.project_timelines', $conversation->context['agent_state']['id']);
        $this->assertSame('waiting_for_slots', $conversation->context['agent_state']['status']);
    }

    public function test_period_follow_up_executes_tool_and_returns_trusted_artifact_link(): void
    {
        $conversation = new AgentFlowConversation(202, [
            'agent_state' => $this->pendingScheduleReportState()->toArray(),
        ]);
        $conversationManager = new AgentFlowConversationManager($conversation);
        $toolRegistry = new AIToolRegistry;
        $toolRegistry->registerTool(new AgentFlowTool('generate_project_timelines_report', [
            'status' => 'success',
            'pdf_url' => 'https://storage.example.test/org-15/reports/timeline.pdf',
            'filename' => 'timeline.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'org-15/reports/timeline.pdf',
        ]));

        $service = $this->makeService($conversationManager, $toolRegistry);

        $result = $service->ask('за последний месяц', 15, $this->makeUser(), 202, [
            'context' => [
                'source_module' => 'ai-assistant',
            ],
        ]);

        $metadata = $conversationManager->assistantMessages()[0]->metadata;

        $this->assertStringContainsString('https://storage.example.test/org-15/reports/timeline.pdf', $result['message']['content']);
        $this->assertSame('pdf', $metadata['artifacts'][0]['type']);
        $this->assertSame('completed', $metadata['agent_state']['status']);
        $this->assertSame('success', $metadata['tool_result']['status']);
        $this->assertSame('generate_project_timelines_report', $metadata['tool_result']['tool_name']);
        $this->assertSame('completed', $conversation->context['agent_state']['status']);
    }

    public function test_executor_success_without_artifacts_returns_failure_without_fake_link(): void
    {
        $conversation = new AgentFlowConversation(303, [
            'agent_state' => $this->pendingScheduleReportState()->toArray(),
        ]);
        $conversationManager = new AgentFlowConversationManager($conversation);
        $toolRegistry = new AIToolRegistry;
        $toolRegistry->registerTool(new AgentFlowTool('generate_project_timelines_report', [
            'status' => 'success',
        ]));

        $service = $this->makeService($conversationManager, $toolRegistry);

        $result = $service->ask('за последний месяц', 15, $this->makeUser(), 303, [
            'context' => [
                'source_module' => 'ai-assistant',
            ],
        ]);

        $metadata = $conversationManager->assistantMessages()[0]->metadata;

        $this->assertStringContainsString('Не удалось сформировать файл отчета', $result['message']['content']);
        $this->assertStringNotContainsString('http', $result['message']['content']);
        $this->assertSame([], $metadata['artifacts']);
        $this->assertSame('failed', $metadata['agent_state']['status']);
        $this->assertSame('failed', $conversation->context['agent_state']['status']);
    }

    public function test_executor_ignores_artifacts_from_another_organization(): void
    {
        $conversation = new AgentFlowConversation(404, [
            'agent_state' => $this->pendingScheduleReportState()->toArray(),
        ]);
        $conversationManager = new AgentFlowConversationManager($conversation);
        $toolRegistry = new AIToolRegistry;
        $toolRegistry->registerTool(new AgentFlowTool('generate_project_timelines_report', [
            'status' => 'success',
            'pdf_url' => 'https://storage.example.test/org-99/reports/timeline.pdf',
            'filename' => 'timeline.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'org-99/reports/timeline.pdf',
        ]));

        $service = $this->makeService($conversationManager, $toolRegistry);

        $result = $service->ask('за последний месяц', 15, $this->makeUser(), 404, [
            'context' => [
                'source_module' => 'ai-assistant',
            ],
        ]);

        $metadata = $conversationManager->assistantMessages()[0]->metadata;

        $this->assertStringNotContainsString('https://storage.example.test/org-99/reports/timeline.pdf', $result['message']['content']);
        $this->assertSame([], $metadata['artifacts']);
        $this->assertSame('failed', $metadata['agent_state']['status']);
    }

    private function makeService(
        AgentFlowConversationManager $conversationManager,
        AIToolRegistry $toolRegistry
    ): AgentFlowAIAssistantService {
        $llmProvider = $this->createMock(LLMProviderInterface::class);
        $llmProvider->expects($this->never())->method('chat');

        $contextBuilder = $this->createMock(ContextBuilder::class);
        $contextBuilder->method('buildContext')->willReturn(['intent' => 'summary']);
        $contextBuilder->method('buildSystemPrompt')->willReturn('system');

        $usageTracker = $this->createMock(UsageTracker::class);
        $usageTracker->method('canMakeRequest')->willReturn(true);
        $usageTracker->method('getUsageStats')->willReturn(['requests_used' => 0]);

        $permissionChecker = $this->createMock(AIPermissionChecker::class);
        $permissionChecker->method('canUseAssistant')->willReturn(true);
        $permissionChecker->method('canAccessOrganizationConversationsInAdmin')->willReturn(false);
        $permissionChecker->method('canExecuteTool')->willReturn(true);
        $permissionChecker->method('isMutationTool')->willReturn(false);

        $accessContextResolver = $this->createMock(AssistantAccessContextResolver::class);
        $accessContextResolver->method('resolve')->willReturn([]);

        $taskOrchestrator = $this->createMock(AssistantTaskOrchestrator::class);
        $taskOrchestrator->method('plan')->willReturn($this->taskPlan());
        $taskOrchestrator->method('buildPayload')->willReturnCallback(
            static function (array $plan, string $answer, array $options = []): array {
                return [
                    'answer' => $answer,
                    'task_type' => $plan['task_type'],
                    'confidence' => $options['confidence'] ?? 'high',
                    'capability' => $plan['capability']['id'] ?? null,
                    'evidence' => $options['tool_evidence'] ?? [],
                    'missing_data' => $options['missing_data'] ?? [],
                    'agent_state' => $options['agent_state'] ?? null,
                    'artifacts' => $options['artifacts'] ?? [],
                    'tool_result' => $options['tool_result'] ?? null,
                ];
            }
        );

        return new AgentFlowAIAssistantService(
            $llmProvider,
            $conversationManager,
            $contextBuilder,
            $this->createMock(IntentRecognizer::class),
            $usageTracker,
            $this->createMock(LoggingService::class),
            $toolRegistry,
            $permissionChecker,
            $accessContextResolver,
            $taskOrchestrator,
            new AssistantAgentStateStore,
            new AssistantAgentPlanner(
                new AssistantCapabilityCatalog,
                new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'))
            ),
            new AssistantAgentExecutor($toolRegistry, $permissionChecker, new AssistantArtifactNormalizer),
            new AssistantResponseVerifier
        );
    }

    private function taskPlan(): array
    {
        return [
            'request' => [
                'message' => 'Сделай отчет по графику работ',
                'goal' => null,
                'desired_mode' => null,
                'allow_actions' => false,
                'context' => $this->projectRequestPayload()['context'],
            ],
            'task_type' => 'summary',
            'capability' => [
                'id' => 'reports',
                'label' => 'Отчеты',
            ],
            'navigation_target' => null,
            'next_actions' => [],
            'access_limits' => [],
            'access_context_public' => [],
        ];
    }

    private function projectRequestPayload(): array
    {
        return [
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
                'ui_state' => [
                    'assistant_report_focus' => 'schedules',
                ],
            ],
        ];
    }

    private function pendingScheduleReportState(): AssistantTaskState
    {
        return new AssistantTaskState(
            id: 'report.project_timelines',
            domain: 'reports',
            capability: 'schedules',
            toolName: 'generate_project_timelines_report',
            status: 'waiting_for_slots',
            slots: [
                new AssistantTaskSlot('period', true),
                new AssistantTaskSlot('project_id', false, 56, 'Строительство склада Литер А'),
            ],
            sourceMessage: 'Сделай отчет по графику работ'
        );
    }

    private function makeUser(): User
    {
        $user = new User;
        $user->id = 7;

        return $user;
    }
}

final class AgentFlowAIAssistantService extends AIAssistantService
{
    protected function resolveOrganization(int $organizationId): Organization
    {
        $organization = new Organization;
        $organization->id = $organizationId;

        return $organization;
    }
}

/**
 * @property array<string, mixed> $context
 */
final class AgentFlowConversation extends Conversation
{
    public bool $saveCalled = false;

    public function __construct(int $id = 0, array $context = [])
    {
        parent::__construct();

        $this->id = $id;
        $this->exists = true;
        $this->context = $context;
    }

    public function save(array $options = []): bool
    {
        $this->saveCalled = true;

        return true;
    }
}

final class AgentFlowConversationManager extends ConversationManager
{
    /**
     * @var Message[]
     */
    public array $messages = [];

    public function __construct(
        private readonly Conversation $conversation
    ) {}

    public function createConversation(int $organizationId, User $user, ?string $title = null): Conversation
    {
        return $this->conversation;
    }

    public function findUserConversation(int $conversationId, User $user, int $organizationId): ?Conversation
    {
        return $this->conversation->id === $conversationId ? $this->conversation : null;
    }

    public function addMessage(
        Conversation $conversation,
        string $role,
        string $content,
        int $tokens = 0,
        string $model = 'gpt-4o-mini',
        array $metadata = []
    ): Message {
        $message = new Message;
        $message->id = count($this->messages) + 1;
        $message->role = $role;
        $message->content = $content;
        $message->tokens_used = $tokens;
        $message->model = $model;
        $message->metadata = $metadata;

        $this->messages[] = $message;

        return $message;
    }

    /**
     * @return Message[]
     */
    public function assistantMessages(): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (Message $message): bool => $message->role === 'assistant'
        ));
    }

    public function getMessagesForContextWithBudget(
        Conversation $conversation,
        int $limit = 6,
        int $maxTotalChars = 4000,
        int $maxUserMessageChars = 500,
        int $maxAssistantMessageChars = 900
    ): array {
        return [];
    }
}

final readonly class AgentFlowTool implements AIToolInterface
{
    public function __construct(
        private string $name,
        private array|string $result
    ) {}

    public function getName(): string
    {
        return $this->name;
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
        return $this->result;
    }
}
