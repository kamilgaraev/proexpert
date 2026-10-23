<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Egress;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantCurrentRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgress;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantEgressRequest;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantProviderFactory;
use App\BusinessModules\Features\AIAssistant\Services\Egress\OrdinaryAssistantScopedContext;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Models\User;
use App\Services\Logging\LoggingService;
use App\Support\AI\TokenBudgetService;
use App\Support\AI\TokenCounter;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\AssistantEgressFixtureAuthority;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;
use Throwable;

final class OrdinaryAssistantFailureBoundaryTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const CANARY = 'offline fragment private canary';

    #[DataProvider('errorBodies')]
    public function test_public_entry_removes_sdk_error_body_and_trace_arguments_after_actual_usage_cleanup(array|string $error, bool $hasUsage): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $history = $logs = $usage = [];
            $body = ['error' => $error, 'model' => 'openai/gpt-6-luna'];
            if ($hasUsage) {
                $body['usage'] = ['input_tokens' => 71, 'output_tokens' => 9];
            }
            $service = $this->service($history, $logs, $usage, new Response(400, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
            $permission = $this->createMock(AIPermissionChecker::class);
            $permission->method('canUseAssistant')->willReturnCallback(fn (): array => $service->dispatch(self::CANARY));
            (new ReflectionProperty(AIAssistantService::class, 'permissionChecker'))->setValue($service, $permission);

            try {
                $service->ask(self::CANARY, 15, self::actor(), requestPayload: ['context' => ['untrusted_document' => self::CANARY]]);
                self::fail('SDK error did not escape the public entry');
            } catch (Throwable $escaped) {
                self::assertCount(1, $history, 'Fixture did not reach actual SDK transport');
                self::assertFalse(str_contains($escaped->getMessage(), self::CANARY), 'Provider error message escaped');
                self::assertFalse(str_contains(json_encode($escaped->getTrace(), JSON_THROW_ON_ERROR), self::CANARY), 'Trace arguments escaped');
                self::assertFalse(str_contains((string) $escaped, self::CANARY), 'Reported exception escaped');
                self::assertFalse(str_contains($escaped->getTraceAsString(), self::CANARY), 'Reported trace escaped');
                self::assertNull($escaped->getPrevious());
                self::assertSame('assistant_provider_failed', $escaped->getMessage());
            }
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
            self::assertCount(1, $history);
            self::assertCount(1, $usage);
            self::assertSame($hasUsage, $usage[0][8]['provider_usage_available']);
            self::assertSame($hasUsage ? [71, 9, 80] : [0, 0, 0], array_slice($usage[0], 5, 3));
            self::assertFalse($usage[0][8]['is_successful']);
            self::assertFalse(str_contains(json_encode($logs, JSON_THROW_ON_ERROR), self::CANARY));
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function errorBodies(): array
    {
        return [
            'object-with-actual-usage' => [['message' => self::CANARY, 'type' => 'invalid_request_error'], true],
            'string-without-usage' => [self::CANARY, false],
            'message-list' => [['message' => [self::CANARY, 'another detail'], 'type' => 'invalid_request_error'], true],
        ];
    }

    #[DataProvider('invalidQueuedRequests')]
    public function test_queued_public_entry_sanitizes_invalid_request_arguments(bool $started): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $lifecycle = (new ReflectionClass(AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
            $history = $logs = $usage = [];
            $service = $this->service($history, $logs, $usage, new Response, lifecycle: $lifecycle);
            app()->instance(AssistantCurrentRequest::class, new AssistantCurrentRequest);
            $request = (new AssistantRequest)->setDateFormat('Y-m-d H:i:s')->forceFill(['id' => 101, 'request_id' => '69fcdfde-5a67-47c4-b722-b88e1905cb0c',
                'organization_id' => 15, 'user_id' => 7, 'started_at' => $started ? now() : null,
                'payload' => ['message' => '', 'untrusted_document' => self::CANARY]]);
            try {
                $service->executeStartedRequest($request, self::actor());
                self::fail('Queued failure did not escape');
            } catch (Throwable $escaped) {
                self::assertInstanceOf(\InvalidArgumentException::class, $escaped);
                self::assertSame('assistant_request_invalid', $escaped->getMessage());
                self::assertNull($escaped->getPrevious());
                self::assertSame([], array_filter($escaped->getTrace(), static fn (array $frame): bool => isset($frame['args'])));
                self::assertFalse(str_contains($escaped->getTraceAsString().(string) $escaped, self::CANARY));
            }
            self::assertSame('0', ini_get('zend.exception_ignore_args'));
            self::assertSame([], $history);
            self::assertSame([], $usage);
            self::assertFalse(app(AssistantCurrentRequest::class)->hasRequest());
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    public static function invalidQueuedRequests(): array
    {
        return [[false], [true]];
    }

    public function test_unselected_legacy_scope_keeps_the_original_exception_after_scope_is_cleared(): void
    {
        $previousResolver = Model::getConnectionResolver();
        $connection = new class extends PostgresConnection
        {
            public function __construct()
            {
                parent::__construct(static fn () => throw new RuntimeException('offline_database_forbidden'));
            }

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                return [];
            }
        };
        $resolver = new ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
        try {
            $scope = (new ReflectionClass(OrdinaryAssistantScopedContext::class))->newInstanceWithoutConstructor();
            $history = $logs = $usage = [];
            $service = $this->service($history, $logs, $usage, new Response, scope: $scope);
            $failure = new RuntimeException(self::CANARY);
            $permission = $this->createMock(AIPermissionChecker::class);
            $permission->method('canUseAssistant')->willThrowException($failure);
            (new ReflectionProperty(AIAssistantService::class, 'permissionChecker'))->setValue($service, $permission);
            try {
                $service->ask('Synthetic legacy request', 15, self::actor());
                self::fail('Legacy exception did not escape');
            } catch (Throwable $escaped) {
                self::assertSame($failure, $escaped);
            }
            self::assertTrue($scope->isGuarded());
            self::assertSame([], $history);
        } finally {
            if ($previousResolver === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($previousResolver);
            }
        }
    }

    public function test_enter_failure_stays_denied_without_original_source_trace_or_legacy_fallback(): void
    {
        $previousResolver = Model::getConnectionResolver();
        $connection = new class extends PostgresConnection
        {
            public function __construct()
            {
                parent::__construct(static fn () => throw new RuntimeException('offline_database_forbidden'));
            }

            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                throw new RuntimeException('offline fragment private canary');
            }
        };
        $resolver = new ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $scope = (new ReflectionClass(OrdinaryAssistantScopedContext::class))->newInstanceWithoutConstructor();
            $history = $logs = $usage = [];
            $service = $this->service($history, $logs, $usage, new Response, scope: $scope);
            try {
                $service->ask(self::CANARY, 15, self::actor());
                self::fail('Unavailable admission fell back');
            } catch (\App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied $denied) {
                self::assertSame('dependency_unavailable', $denied->reason);
                self::assertNull($denied->getPrevious());
                self::assertFalse(str_contains(json_encode($denied->getTrace(), JSON_THROW_ON_ERROR).(string) $denied, self::CANARY));
            }
            self::assertSame([], $history);
            self::assertSame([], $usage);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
            if ($previousResolver === null) {
                Model::unsetConnectionResolver();
            } else {
                Model::setConnectionResolver($previousResolver);
            }
        }
    }

    private static function actor(): User
    {
        return (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]);
    }

    private function service(array &$history, array &$logs, array &$usage, Response $response,
        ?AssistantRequestLifecycle $lifecycle = null, ?OrdinaryAssistantScopedContext $scope = null): OrdinaryFailureServiceHarness
    {
        config(['ai-assistant.llm.provider' => 'timeweb', 'ai-assistant.llm.timeweb.api_key' => 'offline-only-key',
            'ai-assistant.llm.timeweb.base_uri' => 'https://offline.invalid/v1', 'ai-assistant.llm.timeweb.model' => 'openai/gpt-6-luna']);
        $logging = $this->createMock(LoggingService::class);
        $logging->method('technical')->willReturnCallback(static function (string $event, array $context = []) use (&$logs): void {
            $logs[] = [$event, $context];
        });
        $tracker = $this->createMock(UsageTracker::class);
        $tracker->method('recordUsage')->willReturnCallback(static function (...$arguments) use (&$usage): void {
            $usage[] = $arguments;
        });
        $authority = new AssistantEgressFixtureAuthority;
        $egress = new OrdinaryAssistantEgress($authority);
        $provider = (new OrdinaryAssistantProviderFactory($egress, $logging))->make(Middleware::history($history)(new MockHandler([$response])));
        $service = (new ReflectionClass(OrdinaryFailureServiceHarness::class))->newInstanceWithoutConstructor();
        $service->authority = $authority;
        foreach (['llmProvider' => $provider, 'logging' => $logging, 'usageTracker' => $tracker,
            'requestLifecycle' => $lifecycle, 'scopedContext' => $scope, 'ordinaryEgress' => $egress,
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

final class OrdinaryFailureServiceHarness extends AIAssistantService
{
    public AssistantEgressFixtureAuthority $authority;

    public function dispatch(string $query): array
    {
        return $this->requestAssistantResponse([['role' => 'user', 'content' => $query]], [], 15, (new User)->forceFill(['id' => 7, 'current_organization_id' => 15, 'is_active' => true]));
    }

    protected function prepareProviderPayload(array $messages, array $options, int $organizationId, User $user): array
    {
        $prepared = parent::prepareProviderPayload($messages, $options, $organizationId, $user);
        $this->authority->approve(OrdinaryAssistantEgressRequest::capture($prepared[0], $prepared[1], $organizationId, $user, $this->llmProvider->getModel()));

        return $prepared;
    }
}
