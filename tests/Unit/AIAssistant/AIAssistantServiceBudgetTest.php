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
}
