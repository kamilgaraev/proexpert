<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\LLM;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\LLM\AssistantInterruptedProviderUsage;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Facade;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Responses\Responses\CreateResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\AssistantEgressFixtureAuthority;
use Tests\Unit\AIAssistant\UsesAssistantReportUnitAccess;
use WeakReference;

final class AssistantInterruptedProviderUsageTest extends TestCase
{
    use UsesAssistantReportUnitAccess { setUp as private accessSetUp; }

    private const CANARY = 'PRIVATE_RESPONSE_NOT_PUBLISHABLE';

    private User $actor;
    private AssistantRequest $request;
    private AssistantRequestExecutionContext $context;
    private array $usage = [];
    private array $events = [];

    protected function setUp(): void
    {
        $this->accessSetUp();
        config(['ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://offline.invalid/v1', 'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna']);
        $this->actor = $this->createPartialMock(User::class, ['refresh', 'belongsToOrganization']);
        $this->actor->forceFill(['id' => 39, 'is_active' => true, 'current_organization_id' => 15]);
        $this->actor->method('refresh')->willReturnSelf();
        $this->actor->method('belongsToOrganization')->willReturn(true);
        $this->grantReportUnitPermissions($this->actor, ['ai_assistant.chat']);
        $this->request = new AssistantRequest;
        $this->request->forceFill(['id' => 1, 'user_id' => 39, 'organization_id' => 15, 'conversation_id' => null,
            'status' => 'running', 'cancel_requested_at' => null, 'lease_expires_at' => now()->addMinutes(8), 'created_at' => now()]);
        $lifecycle = (new ReflectionClass(AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($lifecycle, 'permissions'))->setValue($lifecycle, app(AIPermissionChecker::class));
        $this->context = new AssistantRequestExecutionContext($lifecycle, $this->request, $this->actor, 60000);
        app()->instance(AssistantRequestExecutionContext::class, $this->context);
    }

    public function test_postresponse_deadline_denies_answer_but_retains_provider_usage_once(): void
    {
        $this->assertInterrupted('deadline', self::nativeUsage(), true);
    }

    public function test_postresponse_cancellation_denies_answer_but_retains_provider_usage_once(): void
    {
        $this->assertInterrupted('cancel', self::nativeUsage(), true);
    }

    public function test_postresponse_model_mismatch_does_not_create_a_known_configured_model_receipt(): void
    {
        $this->assertInterrupted('deadline', self::nativeUsage(), false, 'other/provider-model');
    }

    public function test_pre_dispatch_deadline_does_not_send_or_create_a_zero_usage_receipt(): void
    {
        $history = [];
        $service = $this->service($history, static fn (): Response => throw new RuntimeException('Unexpected offline send'));
        $this->expire();
        try {
            $service->dispatch($this->actor);
            self::fail('Expired request must not reach the provider');
        } catch (AssistantRequestDeadlineExceeded) {
            self::assertSame([], $history);
            self::assertSame([], $this->usage);
        }
    }

    #[DataProvider('unknownUsage')]
    public function test_missing_or_inconsistent_usage_stays_unknown_after_postresponse_deadline(?array $usage): void
    {
        $this->assertInterrupted('deadline', $usage, false);
    }

    public static function unknownUsage(): array
    {
        return ['missing' => [null], 'negative' => [self::nativeUsage(-1, 20, 19)],
            'inconsistent' => [self::nativeUsage(100, 20, 121)], 'overflow' => [self::nativeUsage(PHP_INT_MAX, 1, PHP_INT_MAX)]];
    }

    public function test_receipt_is_scalar_identity_bound_consumed_once_and_weakly_owned(): void
    {
        $native = CreateResponse::from(self::nativeBody(self::nativeUsage()), MetaInformation::from([]));
        $receipt = AssistantInterruptedProviderUsage::fromResponse($native, 'openai/gpt-6-luna');
        self::assertNotNull($receipt);
        $failure = new RuntimeException('fixed_failure');
        $other = new RuntimeException('fixed_failure');
        AssistantInterruptedProviderUsage::attach($failure, $receipt);
        self::assertNull(AssistantInterruptedProviderUsage::take($other));
        self::assertSame(['input_tokens' => 100, 'output_tokens' => 20, 'tokens_used' => 120,
            'provider_usage_available' => true, 'usage_source' => 'provider_response'], AssistantInterruptedProviderUsage::take($failure));
        self::assertNull(AssistantInterruptedProviderUsage::take($failure));
        self::assertNull($failure->getPrevious());
        AssistantInterruptedProviderUsage::attach($failure, $receipt);
        AssistantInterruptedProviderUsage::attach($failure, null);
        self::assertNull(AssistantInterruptedProviderUsage::take($failure));
        AssistantInterruptedProviderUsage::attach($other, $receipt);
        $weak = WeakReference::create($other);
        unset($other);
        gc_collect_cycles();
        self::assertNull($weak->get());
        self::assertNull(AssistantInterruptedProviderUsage::fromResponse(new \stdClass, 'openai/gpt-6-luna'));
    }

    public function test_zero_native_usage_is_a_known_receipt_without_response_content(): void
    {
        $native = CreateResponse::from(self::nativeBody(self::nativeUsage(0, 0, 0)), MetaInformation::from([]));
        $receipt = AssistantInterruptedProviderUsage::fromResponse($native, 'openai/gpt-6-luna');
        self::assertNotNull($receipt);
        $failure = new RuntimeException('fixed_failure');
        AssistantInterruptedProviderUsage::attach($failure, $receipt);
        self::assertSame(['input_tokens' => 0, 'output_tokens' => 0, 'tokens_used' => 0,
            'provider_usage_available' => true, 'usage_source' => 'provider_response'], AssistantInterruptedProviderUsage::take($failure));
        self::assertStringNotContainsString(self::CANARY, serialize($receipt));
    }

    public function test_zero_provider_usage_is_known_and_outside_context_result_is_unchanged(): void
    {
        app()->offsetUnset(AssistantRequestExecutionContext::class);
        $native = self::nativeBody(self::nativeUsage(0, 0, 0));
        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode($native, JSON_THROW_ON_ERROR));
        $provider = new TimewebProvider($this->createMock(LoggingService::class), new Client(['handler' => new MockHandler([$response])]));
        $result = $provider->responses([['role' => 'user', 'content' => 'Synthetic question']]);
        self::assertSame(self::CANARY, $result['content']);
        self::assertTrue($result['provider_usage_available']);
        self::assertSame(0, $result['input_tokens']);
        self::assertSame(0, $result['output_tokens']);
        self::assertSame('resp_interrupted_fixture', $result['provider_response_ref']);
    }

    private function assertInterrupted(string $reason, ?array $nativeUsage, bool $known, string $model = 'openai/gpt-6-luna'): void
    {
        $history = [];
        $service = $this->service($history, function () use ($reason, $nativeUsage, $model): Response {
            $reason === 'deadline' ? $this->expire() : $this->request->cancel_requested_at = now();
            $native = self::nativeBody($nativeUsage);
            $native['model'] = $model;
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($native, JSON_THROW_ON_ERROR));
        });
        try {
            $service->dispatch($this->actor);
            self::fail('Post-response control failure must deny the answer');
        } catch (AssistantRequestDeadlineExceeded|AssistantRequestCancelled $failure) {
            self::assertInstanceOf($reason === 'deadline' ? AssistantRequestDeadlineExceeded::class : AssistantRequestCancelled::class, $failure);
            self::assertCount(1, $history);
            self::assertSame('/v1/responses', $history[0]['request']->getUri()->getPath());
            self::assertCount(1, $this->usage);
            self::assertSame($known, $this->usage[0][8]['provider_usage_available']);
            self::assertFalse($this->usage[0][8]['is_successful']);
            self::assertTrue($this->usage[0][8]['degraded_mode']);
            if ($known) {
                self::assertSame([100, 20, 120], array_slice($this->usage[0], 5, 3));
                self::assertSame('provider_response', $this->usage[0][8]['usage_source']);
            } else {
                self::assertSame('unavailable', $this->usage[0][8]['usage_source']);
            }
            self::assertStringNotContainsString(self::CANARY, json_encode([$this->usage, $this->events], JSON_THROW_ON_ERROR));
            self::assertNull($failure->getPrevious());
        }
    }

    private function expire(): void
    {
        (new ReflectionProperty($this->context, 'deadlineNanoseconds'))->setValue($this->context, hrtime(true) - 1);
    }

    private function service(array &$history, callable $respond): InterruptedUsageServiceHarness
    {
        $logging = $this->createMock(LoggingService::class);
        $logging->method('technical')->willReturnCallback(function (string $event, array $context = []): void { $this->events[] = [$event, $context]; });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $event, array $context = []): void { $this->events[] = [$event, $context]; });
        app()->instance('log', $logger);
        Facade::clearResolvedInstance('log');
        $tracker = $this->createMock(UsageTracker::class);
        $tracker->method('recordUsage')->willReturnCallback(function (...$arguments): void { $this->usage[] = $arguments; });
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $provider = (new OrdinaryAssistantProviderFactory($egress, $logging))->make(Middleware::history($history)(new MockHandler([$respond])));
        $service = (new ReflectionClass(InterruptedUsageServiceHarness::class))->newInstanceWithoutConstructor();
        $service->authority = $authority;
        foreach (['llmProvider' => $provider, 'logging' => $logging, 'usageTracker' => $tracker,
            'requestLifecycle' => null, 'scopedContext' => null, 'ordinaryEgress' => $egress,
            'tokenBudget' => new TokenBudgetService(new TokenCounter(new class
            {
                public function encode(string $text): array { return array_fill(0, mb_strlen($text), 1); }
            }))] as $name => $value) {
            (new ReflectionProperty(AIAssistantService::class, $name))->setValue($service, $value);
        }
        return $service;
    }

    private static function nativeUsage(int $input = 100, int $output = 20, int $total = 120): array
    {
        return ['input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $total,
            'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]];
    }

    private static function nativeBody(?array $usage): array
    {
        return ['id' => 'resp_interrupted_fixture', 'object' => 'response', 'created_at' => 1791540000, 'status' => 'completed',
            'model' => 'openai/gpt-6-luna', 'incomplete_details' => null,
            'output' => [['type' => 'message', 'id' => 'msg_interrupted_fixture', 'status' => 'completed', 'role' => 'assistant',
                'content' => [['type' => 'output_text', 'text' => self::CANARY, 'annotations' => []]]]],
            'max_output_tokens' => 128, 'parallel_tool_calls' => false, 'previous_response_id' => null, 'temperature' => null,
            'top_p' => null, 'tool_choice' => 'auto', 'tools' => [], 'usage' => $usage];
    }
}

final class InterruptedUsageServiceHarness extends AIAssistantService
{
    public AssistantEgressFixtureAuthority $authority;

    public function dispatch(User $actor): array
    {
        return $this->requestAssistantResponse([['role' => 'user', 'content' => 'Synthetic question']], [], 15, $actor);
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        $prepared = parent::prepareProviderPayload($messages, $options, $organizationId, $user);
        $this->authority->approve(OrdinaryAssistantEgressRequest::capture($prepared[0], $prepared[1], $organizationId, $user, $this->llmProvider->getModel()));
        return $prepared;
    }
}
