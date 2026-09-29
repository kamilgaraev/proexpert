<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebProvider;
use App\BusinessModules\Features\AIAssistant\Services\LLM\TimewebResponsesAdapter;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Services\Credits\AICreditEconomicsMonitor;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class TimewebResponsesUsageAvailabilityTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    #[DataProvider('sdkCases')]
    public function test_sdk_null_usage_is_unknown_and_actual_zero_usage_remains_known(string $status, bool $available): void
    {
        $result = (new TimewebResponsesAdapter())->result(self::response($status, $available), 'openai/gpt-6-luna');
        self::assertSame($available, $result['provider_usage_available']);
        self::assertSame($available ? 0 : null, $result['input_tokens']);
        self::assertSame($available ? 0 : null, $result['output_tokens']);
        self::assertSame(0, $result['tokens_used']);
        self::assertSame($available ? 'provider_response' : 'unavailable', $result['usage_source']);
        self::assertSame($status === 'completed' ? 'Подтверждённый текст' : '', $result['content']);
        self::assertSame($status === 'completed' ? 'stop' : 'length', $result['finish_reason']);
        self::assertArrayNotHasKey('tool_calls', $result);
    }

    public static function sdkCases(): array
    {
        return [['completed', false], ['incomplete', false], ['completed', true], ['incomplete', true]];
    }

    #[DataProvider('availabilityCases')]
    public function test_real_provider_does_not_fabricate_zero_input_calibration_for_missing_sdk_usage(bool $available): void
    {
        config(['ai-assistant.llm.timeweb.api_key' => 'fixture-only-key']);
        $cache = $this->createMock(Repository::class);
        $cache->method('get')->willReturn(1.1);
        $cache->expects(self::never())->method('forever');
        $cache->expects(self::never())->method('put');
        app()->instance('cache', new class($cache) {
            public function __construct(private readonly Repository $cache) {}
            public function store(): Repository { return $this->cache; }
        });
        $body = json_encode(self::response('completed', $available)->toArray(), JSON_THROW_ON_ERROR);
        $handler = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $body)]);
        $provider = new TimewebProvider($this->createMock(LoggingService::class), new Client(['handler' => HandlerStack::create($handler)]));
        $result = $provider->chat([['role' => 'user', 'content' => 'Покажи текущие данные']], ['profile' => 'short']);
        self::assertSame('Подтверждённый текст', $result['content']);
        self::assertSame($available, $result['provider_usage_available']);
        self::assertSame($available ? 0 : null, $result['token_calibration']['actual_input_tokens']);
        self::assertSame($available, $result['token_calibration']['persisted']);
        self::assertFalse($result['token_calibration']['profile_input_exceeded']);
        self::assertSame(0, $handler->count());
    }

    #[DataProvider('availabilityCases')]
    public function test_main_monitor_receipt_preserves_explicit_availability_and_credit_attempt_identity(bool $available): void
    {
        $service = (new ReflectionClass(MissingUsageReceiptService::class))->newInstanceWithoutConstructor();
        $tracker = $this->createMock(UsageTracker::class);
        $tracker->expects(self::once())->method('recordUsage')->with(15, 7, 'timeweb', 'openai/gpt-6-luna', 'assistant_chat', 0, 0, 0,
            self::callback(static fn (array $receipt): bool => $receipt['provider_usage_available'] === $available
                && $receipt['cost_available'] === $available && $receipt['usage_source'] === ($available ? 'provider_response' : 'unavailable')
                && $receipt['credit_usage_key'] === 'missing-usage-fixture:call:1' && $receipt['usage_key'] === 'missing-usage-fixture:call:1'
                && $receipt['is_successful'] === true));
        foreach (['usageTracker' => $tracker, 'logging' => $this->createMock(LoggingService::class),
            'activeRequest' => new AssistantRequest(['request_id' => 'missing-usage-fixture'])] as $property => $value) {
            (new ReflectionProperty(AIAssistantService::class, $property))->setValue($service, $value);
        }
        $actor = new User();
        $actor->id = 7;
        $service->record(['input_tokens' => 0, 'output_tokens' => 0, 'tokens_used' => 0, 'provider_usage_available' => $available,
            'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna'], $actor);
    }

    public static function availabilityCases(): array { return [[false], [true]]; }

    public function test_unknown_success_is_counted_once_as_incomplete_cost_coverage_across_both_journals(): void
    {
        $metadata = ['usage_key' => 'unknown-call:1', 'credit_usage_key' => 'unknown-call:1',
            'provider_usage_available' => false, 'cost_available' => false, 'cost_is_estimate' => false];
        $report = (new AICreditEconomicsMonitor())->evaluate(
            [['operation' => 'assistant_chat', 'cost_micro_rub' => 0, 'currency' => 'RUB', 'is_successful' => true, 'metadata' => $metadata]],
            [['operation' => 'assistant_chat', 'total_cost_rub' => 0, 'metadata' => $metadata]], [], []);
        self::assertSame('coverage_incomplete', $report['state']);
        self::assertSame(1, $report['unknown_cost_count']);
        self::assertSame(1, $report['provider_call_count']);
        self::assertSame(1, $report['deduplicated_usage_count']);
        self::assertSame(0, $report['external_cost_micro_rub']);
        self::assertNull($report['external_cost_revenue_ratio']);
    }

    private static function response(string $status, bool $available): CreateResponse
    {
        return CreateResponse::fake(['model' => 'openai/gpt-6-luna', 'status' => $status,
            'incomplete_details' => $status === 'incomplete' ? ['reason' => 'max_output_tokens'] : null,
            'output' => [['type' => 'message', 'id' => 'msg_usage_fixture', 'role' => 'assistant', 'status' => $status,
                'content' => [['type' => 'output_text', 'text' => 'Подтверждённый текст', 'annotations' => []]]]],
            'usage' => $available ? ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
                'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]] : null,
        ], strategy: OverrideStrategy::Replace);
    }
}

final class MissingUsageReceiptService extends AIAssistantService
{
    public function record(array $response, User $actor): void { $this->recordAssistantProviderUsage($response, 15, $actor, [], false, true, 1); }
}
