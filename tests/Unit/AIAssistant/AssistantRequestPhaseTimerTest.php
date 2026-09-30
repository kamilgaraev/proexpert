<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestPhaseTimer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Throwable;

final class AssistantRequestPhaseTimerTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const REQUEST_ID = 'efe528e9-b1bd-4161-b804-56faafac432a';

    #[DataProvider('outcomes')]
    public function test_logging_does_not_change_the_operation_result_or_original_exception(string $failureType, bool $loggerThrows): void
    {
        $logger = $this->logger($loggerThrows);
        $failure = match ($failureType) {
            'cancelled' => new AssistantRequestCancelled,
            'deadline' => new AssistantRequestDeadlineExceeded,
            'failed' => new RuntimeException('private_failure_fixture'),
            default => null,
        };
        $result = ['private_content_fixture' => new \stdClass];
        $executions = 0;
        try {
            $actual = AssistantRequestPhaseTimer::run(self::REQUEST_ID, 'provider_prepare', function () use ($failure, $result, &$executions): array {
                $executions++;
                if ($failure !== null) {
                    throw $failure;
                }

                return $result;
            });
            $this->assertNull($failure);
            $this->assertSame($result, $actual);
        } catch (Throwable $actual) {
            $this->assertSame($failure, $actual);
        }

        $this->assertSame(1, $executions);
        $this->assertCount(1, $logger->records);
        $record = $logger->records[0];
        $this->assertSame('info', $record['level']);
        $this->assertSame('ai.assistant.request_phase_completed', $record['event']);
        $metadata = $record['context'];
        $this->assertSame(['request_id', 'phase', 'duration_ms', 'success', 'exception_class'], array_keys($metadata));
        $this->assertSame(self::REQUEST_ID, $metadata['request_id']);
        $this->assertSame('provider_prepare', $metadata['phase']);
        $this->assertGreaterThanOrEqual(0, $metadata['duration_ms']);
        $this->assertSame($failure === null, $metadata['success']);
        $this->assertSame($failure === null ? null : $failure::class, $metadata['exception_class']);
        $this->assertStringNotContainsString('private_', json_encode($record, JSON_THROW_ON_ERROR));
    }

    public static function outcomes(): array
    {
        return [['none', false], ['none', true], ['failed', false], ['failed', true], ['cancelled', true], ['deadline', true]];
    }

    public function test_unknown_phase_and_missing_or_invalid_uuid_do_not_emit_events(): void
    {
        $logger = $this->logger();
        foreach ([[null, 'tool'], ['private_question_fixture', 'tool'], [self::REQUEST_ID, 'private_tool_name_fixture']] as [$requestId, $phase]) {
            $this->assertSame('unchanged', AssistantRequestPhaseTimer::run($requestId, $phase, static fn (): string => 'unchanged'));
        }
        $this->assertSame([], $logger->records);
    }

    public function test_tool_outcome_is_allowlisted_without_exposing_other_result_fields(): void
    {
        $logger = $this->logger();
        $result = ['status' => 'unavailable', 'reason' => 'read_timed_out', 'error' => 'private_error_fixture', 'stock' => ['private_stock_fixture']];
        $this->assertSame($result, AssistantRequestPhaseTimer::run(self::REQUEST_ID, 'tool', static fn (): array => $result));
        AssistantRequestPhaseTimer::run(self::REQUEST_ID, 'stock_read', static fn (): array => ['status' => 'private_status_fixture', 'reason' => 'private_reason_fixture']);

        $this->assertSame('unavailable', $logger->records[0]['context']['outcome']);
        $this->assertSame('read_timed_out', $logger->records[0]['context']['reason']);
        $this->assertNull($logger->records[1]['context']['outcome']);
        $this->assertNull($logger->records[1]['context']['reason']);
        $this->assertStringNotContainsString('private_', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_sql_timeout_records_only_registered_reason_and_preserves_exception(): void
    {
        $logger = $this->logger();
        $previous = new \PDOException('private_sql_error_fixture');
        $previous->errorInfo = ['57014'];
        $failure = new QueryException('controlled', 'private_sql_fixture', ['private_binding_fixture'], $previous);
        try {
            AssistantRequestPhaseTimer::run(self::REQUEST_ID, 'stock_read', static fn () => throw $failure);
            $this->fail('The original database failure must propagate.');
        } catch (QueryException $actual) {
            $this->assertSame($failure, $actual);
        }
        $this->assertFalse($logger->records[0]['context']['success']);
        $this->assertSame('read_timed_out', $logger->records[0]['context']['reason']);
        $this->assertStringNotContainsString('private_', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_finish_is_once_and_nested_phases_keep_independent_scope(): void
    {
        $logger = $this->logger();
        $timer = AssistantRequestPhaseTimer::start(self::REQUEST_ID, 'preparation');
        AssistantRequestPhaseTimer::run(self::REQUEST_ID, 'catalog', static fn (): int => 1);
        $timer->finish();
        $timer->finish(new RuntimeException('private_late_failure_fixture'));

        $this->assertSame(['catalog', 'preparation'], array_column(array_column($logger->records, 'context'), 'phase'));
        $this->assertTrue($logger->records[1]['context']['success']);
    }

    public function test_unavailable_logger_does_not_fail_a_completed_operation(): void
    {
        Log::clearResolvedInstance('log');
        app()->offsetUnset('log');
        $this->assertSame(7, AssistantRequestPhaseTimer::run(self::REQUEST_ID, 'job_startup', static fn (): int => 7));
    }

    public function test_service_phases_require_active_queued_execution_and_do_not_leak_after_scope_reset(): void
    {
        $logger = $this->logger();
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $request = (new AssistantRequest)->forceFill(['request_id' => self::REQUEST_ID]);
        (new ReflectionProperty(AIAssistantService::class, 'activeRequest'))->setValue($service, $request);
        $measure = new ReflectionMethod(AIAssistantService::class, 'measurePhase');
        $operation = static fn (): int => 7;
        $this->assertSame(7, $measure->invoke($service, 'request_permission', $operation));
        $this->assertSame([], $logger->records);
        $enabled = new ReflectionProperty(AIAssistantService::class, 'runtimeTimingEnabled');
        $enabled->setValue($service, true);
        $this->assertSame(7, $measure->invoke($service, 'request_permission', $operation));
        $enabled->setValue($service, false);
        $this->assertSame(7, $measure->invoke($service, 'request_permission', $operation));
        $this->assertCount(1, $logger->records);
    }

    private function logger(bool $throws = false): AbstractLogger
    {
        $logger = new class($throws) extends AbstractLogger
        {
            public array $records = [];

            public function __construct(private readonly bool $throws) {}

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'event' => (string) $message, 'context' => $context];
                if ($this->throws) {
                    throw new RuntimeException('private_logger_failure_fixture');
                }
            }
        };
        Log::swap($logger);

        return $logger;
    }
}
