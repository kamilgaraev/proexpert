<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Models\Message;
use App\BusinessModules\Features\AIAssistant\Services as Services;
use App\BusinessModules\Features\AIAssistant\Services\Agent as Agent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use Tests\Support\AssistantEgressFixtureRuntime;

final class AssistantGuardedLoopDiagnosticsIntegrationTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const TOOL = 'search_assistant_documents';

    private const CANARY = 'PRIVATE_query_call_content_canary';

    #[DataProvider('observers')]
    public function test_production_loop_and_sdk_entry_observe_zero_one_two_outputs_without_content(bool $throwObserver): void
    {
        $history = $events = [];
        $content = str_repeat('Ж', 504).self::CANARY;
        $service = $this->service($history, $events, $content, $throwObserver);
        $service->ask('Сопоставь сведения', 15, (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]));
        self::assertCount(3, $history);
        self::assertSame([0, 1, 2], array_map(static fn (array $messages): int => count(array_filter($messages,
            static fn (array $message): bool => ($message['type'] ?? null) === 'function_call_output')), $history));
        $last = array_values(array_filter($history[2], static fn (array $message): bool => ($message['type'] ?? null) === 'function_call_output'));
        self::assertSame(['call_1_'.self::CANARY, 'call_2_'.self::CANARY], array_column($last, 'call_id'));
        self::assertSame([], json_decode($last[0]['output'], true, 512, JSON_THROW_ON_ERROR)['data']['fragments']);
        self::assertSame($content, json_decode($last[1]['output'], true, 512, JSON_THROW_ON_ERROR)['data']['fragments'][0]['content']);
        if ($throwObserver) {
            self::assertSame([], $events);
            return;
        }
        $tools = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'ai.assistant.guarded_tool_result'));
        $providers = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'ai.assistant.provider_call_completed'));
        self::assertCount(2, $tools);
        self::assertCount(3, $providers);
        self::assertSame([1, 2], array_column(array_column($tools, 1), 'provider_ordinal'));
        self::assertSame([0, 1], array_column(array_column($tools, 1), 'fragment_count'));
        self::assertSame([0, strlen($content)], array_column(array_column($tools, 1), 'selected_content_bytes'));
        self::assertSame([true, false], array_column(array_column($tools, 1), 'empty'));
        self::assertSame([null, false], array_column(array_column($tools, 1), 'repeat_query'));
        self::assertSame([0, 1, 2], array_column(array_column($providers, 1), 'canonical_outputs_expected'));
        self::assertSame([0, 1, 2], array_column(array_column($providers, 1), 'canonical_outputs_in_sdk_input'));
        self::assertSame([null, true, true], array_column(array_column($providers, 1), 'all_canonical_outputs_in_sdk_input'));
        self::assertStringNotContainsString(self::CANARY, json_encode($events, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('DIFFERENT_PRIVATE_QUERY', json_encode($events, JSON_THROW_ON_ERROR));
    }

    public static function observers(): array
    {
        return ['normal' => [false], 'logger-failure' => [true]];
    }

    public function test_no_followup_sdk_entry_is_not_reported_as_delivered(): void
    {
        $history = $events = [];
        $service = $this->service($history, $events, 'synthetic', false);
        $service->beforeFollowup = new AssistantRequestDeadlineExceeded;
        try {
            $service->ask('Сопоставь сведения', 15, (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]));
            self::fail('The existing deadline failure must escape');
        } catch (AssistantRequestDeadlineExceeded) {
        }
        self::assertCount(1, $history);
        $tools = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'ai.assistant.guarded_tool_result'));
        $providers = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'ai.assistant.provider_call_completed'));
        self::assertCount(1, $tools);
        self::assertTrue($tools[0][1]['empty']);
        self::assertCount(1, $providers);
        self::assertSame(0, $providers[0][1]['canonical_outputs_expected']);
        self::assertNull($providers[0][1]['all_canonical_outputs_in_sdk_input']);
    }

    public function test_nonguarded_production_gate_emits_no_new_fields_and_finally_clears_state(): void
    {
        $history = $events = [];
        $service = $this->service($history, $events, 'synthetic', false);
        $service->enableTelemetry = false;
        $state = new ReflectionProperty(Services\AIAssistantService::class, 'guardedLoopDiagnostics');
        $state->setValue($service, new Services\AssistantGuardedLoopDiagnostics('d89d43e5-fa40-4c3f-a48c-bfbc45e74fe6'));
        $service->ask('Сопоставь сведения', 15, (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]));
        self::assertCount(3, $history);
        self::assertNull($state->getValue($service));
        foreach ($events as [$name, $event]) {
            self::assertNotSame('ai.assistant.guarded_tool_result', $name);
            self::assertArrayNotHasKey('canonical_outputs_expected', $event);
        }
    }

    private function service(array &$history, array &$events, string $content, bool $throwObserver): GuardedLoopDiagnosticServiceFixture
    {
        config(['ai-assistant-credits.profiles.normal' => ['input_tokens' => 24576, 'output_tokens' => 2048, 'max_calls' => 3]]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $event, array $context = []) use (&$events, $throwObserver): void {
            if ($throwObserver) {
                throw new RuntimeException(self::CANARY);
            }
            $events[] = [$event, $context];
        });
        app()->instance('log', $logger);
        Facade::clearResolvedInstance('log');
        $logging = $this->createMock(LoggingService::class);
        $registry = new Services\AIToolRegistry;
        $results = [['status' => 'success', 'data' => ['kind' => 'untrusted_search_results', 'fragments' => []]],
            ['status' => 'success', 'data' => ['kind' => 'untrusted_search_results', 'fragments' => [
                ['content' => $content, 'content_range' => ['truncated' => false]]]]]];
        $tool = $this->createMock(AIToolInterface::class);
        $tool->method('getName')->willReturn(self::TOOL);
        $tool->method('getDescription')->willReturn('Synthetic readonly fixture');
        $tool->method('getParametersSchema')->willReturn(['type' => 'object', 'properties' => ['query' => ['type' => 'string']],
            'required' => ['query'], 'additionalProperties' => false]);
        $tool->method('execute')->willReturnCallback(static function () use (&$results): array { return array_shift($results); });
        $registry->registerTool($tool);
        $responses = new class($history)
        {
            private int $calls = 0;

            public function __construct(private array &$history) {}

            public function responses(array $messages, array $options): array
            {
                $this->history[] = $messages;
                $ordinal = ++$this->calls;
                $output = $ordinal < 3 ? [['type' => 'function_call', 'id' => 'item_'.$ordinal, 'status' => 'completed',
                    'call_id' => 'call_'.$ordinal.'_PRIVATE_query_call_content_canary', 'name' => 'search_assistant_documents',
                    'arguments' => json_encode(['query' => $ordinal === 1 ? 'PRIVATE_query_call_content_canary' : 'DIFFERENT_PRIVATE_QUERY'], JSON_THROW_ON_ERROR)]] : [];

                return ['output' => $output, 'content' => $ordinal === 3 ? 'Готово.' : '', 'input_tokens' => 300, 'output_tokens' => 20];
            }
        };
        $runtime = new AssistantEgressFixtureRuntime(15, 7, $responses, $logging);
        $conversation = $this->getMockBuilder(Conversation::class)->onlyMethods(['save'])->getMock();
        $conversation->forceFill(['id' => 7, 'organization_id' => 15, 'user_id' => 7, 'context' => []]);
        $conversation->method('save')->willReturn(true);
        $manager = $this->createMock(Services\ConversationManager::class);
        $manager->method('getMessagesForContextWithBudget')->willReturn([]);
        $manager->method('getSummary')->willReturn(null);
        $manager->method('updateContext')->willReturn($conversation);
        $manager->method('addMessage')->willReturnCallback(static fn (Conversation $conversation, string $role, string $content): Message =>
            new Message(['id' => 11, 'role' => $role, 'content' => $content, 'metadata' => [], 'created_at' => now()]));
        $builder = $this->createMock(Services\ContextBuilder::class);
        $builder->method('buildSystemPrompt')->willReturn('Synthetic system');
        $permissions = $this->createMock(Services\AIPermissionChecker::class);
        foreach (['canUseAssistant', 'canExecuteTool', 'canExposeTool'] as $method) {
            $permissions->method($method)->willReturn(true);
        }
        $usage = $this->createMock(Services\UsageTracker::class);
        $usage->method('canMakeRequest')->willReturn(true);
        $usage->method('getUsageStats')->willReturn([]);
        $orchestrator = $this->createMock(Services\AssistantTaskOrchestrator::class);
        $orchestrator->method('plan')->willReturnCallback(static fn (string $query): array => ['request' => ['message' => $query, 'context' => [], 'allow_actions' => false],
            'task_type' => 'summary', 'capability' => [], 'access_context_public' => []]);
        $orchestrator->method('buildPayload')->willReturnCallback(static fn (array $plan, string $answer, array $options): array => $options + ['answer' => $answer]);
        $service = new GuardedLoopDiagnosticServiceFixture($runtime->provider, $manager, $builder, $this->createMock(Services\IntentRecognizer::class), $usage,
            $logging, $registry, $permissions, $this->createMock(Services\AssistantAccessContextResolver::class), $orchestrator,
            new Agent\AssistantAgentStateStore, new Agent\AssistantAgentPlanner(new Agent\AssistantCapabilityCatalog, new Agent\AssistantPeriodResolver),
            new Agent\AssistantAgentExecutor($registry, $permissions, new Agent\AssistantArtifactNormalizer), new Agent\AssistantResponseVerifier,
            tokenBudget: new TokenBudgetService(new TokenCounter(new class
            {
                public function encode(string $text): array { return array_fill(0, (int) ceil(mb_strlen($text) / 4), 1); }
            })), ordinaryEgress: $runtime->egress);
        $service->runtime = $runtime;
        $service->conversation = $conversation;

        return $service;
    }
}

final class GuardedLoopDiagnosticServiceFixture extends Services\AIAssistantService
{
    public AssistantEgressFixtureRuntime $runtime;

    public Conversation $conversation;

    private ?Services\AssistantGuardedLoopDiagnostics $observer = null;

    public bool $enableTelemetry = true;

    public ?Throwable $beforeFollowup = null;

    protected function guardedLoopDiagnostics(): ?Services\AssistantGuardedLoopDiagnostics
    {
        if (! $this->enableTelemetry) {
            return parent::guardedLoopDiagnostics();
        }
        return $this->observer ??= new Services\AssistantGuardedLoopDiagnostics('d89d43e5-fa40-4c3f-a48c-bfbc45e74fe6');
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        if ($this->beforeFollowup !== null && array_filter($messages, static fn (array $message): bool => ($message['type'] ?? null) === 'function_call_output') !== []) {
            throw $this->beforeFollowup;
        }
        $prepared = parent::prepareProviderPayload($messages, $options, $organizationId, $user);
        $this->runtime->approvePrepared($prepared[0], $prepared[1], $organizationId, $user);

        return $prepared;
    }

    protected function resolveRelevantToolNames(array $taskPlan): array
    {
        return ['search_assistant_documents'];
    }

    protected function getOrCreateConversation(?int $conversationId, int $organizationId, User $user): Conversation
    {
        return $this->conversation;
    }

    protected function resolveOrganization(int $organizationId): Organization
    {
        return (new Organization)->forceFill(['id' => $organizationId]);
    }

    protected function handleAgentFlow(string $query, int $organizationId, User $user, Conversation $conversation, array $taskPlan): ?array
    {
        return null;
    }
}
