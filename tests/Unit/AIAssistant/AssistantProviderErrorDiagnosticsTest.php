<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantProviderFailure;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantProviderErrorDiagnostics;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\AssistantEgressFixtureAuthority;

final class AssistantProviderErrorDiagnosticsTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const CANARY = 'PRIVATE_provider_diagnostic_canary';

    public function test_actual_sdk_failure_is_logged_with_safe_diagnostics_before_boundary(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $history = $events = $usage = [];
            $service = $this->service($history, $events, $usage);
            $permission = $this->createMock(AIPermissionChecker::class);
            $permission->method('canUseAssistant')->willReturnCallback(fn (): array => $service->dispatch(self::CANARY));
            (new ReflectionProperty(AIAssistantService::class, 'permissionChecker'))->setValue($service, $permission);

            try {
                $service->ask(self::CANARY, 15, self::actor());
                self::fail('SDK error must escape through the safe public boundary');
            } catch (AssistantProviderFailure $failure) {
                self::assertSame('assistant_provider_failed', $failure->getMessage());
                self::assertNull($failure->getPrevious());
                self::assertStringNotContainsString(self::CANARY, (string) $failure);
                self::assertStringNotContainsString(self::CANARY, json_encode($failure->getTrace(), JSON_THROW_ON_ERROR));
            }

            self::assertCount(1, $history);
            self::assertCount(1, $usage);
            self::assertFalse($usage[0][8]['provider_usage_available']);
            $calls = array_values(array_filter($events, static fn (array $event): bool => $event[0] === 'ai.assistant.provider_call_completed'));
            self::assertCount(1, $calls);
            self::assertSame(ErrorException::class, $calls[0][1]['exception_class']);
            self::assertFalse($calls[0][1]['success']);
            self::assertArrayHasKey('provider_http_status', $calls[0][1]);
            self::assertSame(400, $calls[0][1]['provider_http_status']);
            self::assertSame('invalid_request_error', $calls[0][1]['provider_error_type']);
            self::assertSame('unsupported_parameter', $calls[0][1]['provider_error_code']);
            self::assertStringNotContainsString(self::CANARY, json_encode($events, JSON_THROW_ON_ERROR));
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    #[DataProvider('errorResponses')]
    public function test_actual_sdk_errors_expose_only_status_and_closed_identifiers(int $status, array|string $error, ?string $type, ?string $code): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response($status, ['Content-Type' => 'application/json', 'X-Private' => self::CANARY],
            json_encode(['error' => $error, 'private' => self::CANARY], JSON_THROW_ON_ERROR))]));
        $handler->push(Middleware::history($history));
        $client = \OpenAI::factory()->withApiKey('offline-only-key')->withBaseUri('https://offline.invalid/v1')
            ->withHttpClient(new Client(['handler' => $handler]))->make();
        try {
            $client->responses()->create(['model' => 'openai/gpt-6-luna', 'input' => [['role' => 'user', 'content' => self::CANARY]]]);
            self::fail('Actual SDK must reject the synthetic HTTP error');
        } catch (ErrorException $failure) {
            $failure->response->getBody()->close();
            $diagnostics = AssistantProviderErrorDiagnostics::fromFailure($failure);
            self::assertSame(['provider_http_status' => $status, 'provider_error_type' => $type, 'provider_error_code' => $code], $diagnostics);
            self::assertStringNotContainsString(self::CANARY, json_encode($diagnostics, JSON_THROW_ON_ERROR));
        }
        self::assertCount(1, $history);
    }

    public static function errorResponses(): array
    {
        return [
            'invalid-parameter' => [400, ['message' => self::CANARY, 'type' => 'invalid_request_error', 'code' => 'unsupported_parameter', 'param' => self::CANARY], 'invalid_request_error', 'unsupported_parameter'],
            'auth' => [401, ['message' => self::CANARY, 'type' => 'authentication_error', 'code' => 'invalid_api_key'], 'authentication_error', 'invalid_api_key'],
            'model' => [404, ['message' => self::CANARY, 'type' => 'invalid_request_error', 'code' => 'model_not_found'], 'invalid_request_error', 'model_not_found'],
            'server' => [503, ['message' => self::CANARY, 'type' => 'server_error', 'code' => 'server_error'], 'server_error', 'server_error'],
            'untrusted-identifiers' => [400, ['message' => self::CANARY, 'type' => self::CANARY, 'code' => self::CANARY], null, null],
            'string-error' => [422, self::CANARY, null, null],
            'invalid-field-types' => [400, ['message' => self::CANARY, 'type' => ['private' => self::CANARY], 'code' => ['private' => self::CANARY]], null, null],
            'numeric-code' => [500, ['message' => self::CANARY, 'type' => 'api_error', 'code' => 12345], 'api_error', null],
            'case-and-whitespace' => [400, ['message' => self::CANARY, 'type' => ' INVALID_REQUEST_ERROR ', 'code' => 'unsupported_parameter'."\n".self::CANARY], null, null],
        ];
    }

    public function test_unrelated_failure_and_success_have_no_inferred_provider_details(): void
    {
        foreach ([null, new RuntimeException(self::CANARY, 400)] as $failure) {
            self::assertSame(['provider_http_status' => null, 'provider_error_type' => null, 'provider_error_code' => null],
                AssistantProviderErrorDiagnostics::fromFailure($failure));
        }
    }

    private static function actor(): User
    {
        return (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]);
    }

    private function service(array &$history, array &$events, array &$usage): ProviderDiagnosticServiceHarness
    {
        config(['ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://offline.invalid/v1', 'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $event, array $context = []) use (&$events): void {
            $events[] = [$event, $context];
        });
        app()->instance('log', $logger);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('log');
        $logging = $this->createMock(LoggingService::class);
        $logging->method('technical')->willReturnCallback(static function (string $event, array $context = []) use (&$events): void {
            $events[] = [$event, $context];
        });
        $tracker = $this->createMock(UsageTracker::class);
        $tracker->method('recordUsage')->willReturnCallback(static function (...$arguments) use (&$usage): void {
            $usage[] = $arguments;
        });
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $response = new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => [
            'message' => self::CANARY, 'type' => 'invalid_request_error', 'code' => 'unsupported_parameter']], JSON_THROW_ON_ERROR));
        $provider = (new OrdinaryAssistantProviderFactory($egress, $logging))->make(Middleware::history($history)(new MockHandler([$response])));
        $service = (new ReflectionClass(ProviderDiagnosticServiceHarness::class))->newInstanceWithoutConstructor();
        $service->authority = $authority;
        foreach (['llmProvider' => $provider, 'logging' => $logging, 'usageTracker' => $tracker,
            'requestLifecycle' => null, 'scopedContext' => null, 'ordinaryEgress' => $egress,
            'tokenBudget' => new TokenBudgetService(new TokenCounter(new class
            {
                public function encode(string $text): array
                {
                    return array_fill(0, mb_strlen($text), 1);
                }
            }))] as $name => $value) {
            (new ReflectionProperty(AIAssistantService::class, $name))->setValue($service, $value);
        }

        return $service;
    }
}

final class ProviderDiagnosticServiceHarness extends AIAssistantService
{
    public AssistantEgressFixtureAuthority $authority;

    public function dispatch(string $query): array
    {
        return $this->requestAssistantResponse([['role' => 'user', 'content' => $query]], [], 15,
            (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]));
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        $prepared = parent::prepareProviderPayload($messages, $options, $organizationId, $user);
        $this->authority->approve(OrdinaryAssistantEgressRequest::capture($prepared[0], $prepared[1], $organizationId, $user, $this->llmProvider->getModel()));

        return $prepared;
    }
}
