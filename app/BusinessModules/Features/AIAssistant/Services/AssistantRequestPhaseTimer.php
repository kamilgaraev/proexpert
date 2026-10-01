<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\QueryException;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantRequestPhaseTimer
{
    private const PHASES = [
        'job_startup', 'service_graph', 'attachment_prepare', 'image_parts', 'request_permission', 'conversation',
        'request_context', 'access_context', 'task_plan', 'preparation', 'messages', 'catalog',
        'tool_definitions', 'provider_prepare', 'tool', 'stock_read', 'final_verification', 'request_complete', 'summary_publish', 'greeting',
        'read_permit_wait', 'read_permit_hold',
    ];

    private readonly int $started;

    private bool $finished = false;

    private function __construct(private readonly ?string $requestId, private readonly string $phase)
    {
        $this->started = hrtime(true);
    }

    public static function start(?string $requestId, string $phase): self
    {
        $valid = $requestId !== null && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestId) === 1
            && in_array($phase, self::PHASES, true);

        return new self($valid ? $requestId : null, $phase);
    }

    public static function run(?string $requestId, string $phase, callable $operation): mixed
    {
        $timer = self::start($requestId, $phase);
        $failure = null;
        $result = null;
        try {
            return $result = $operation();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            $timer->finish($failure, $result);
        }
    }

    public static function recordDuration(
        ?string $requestId,
        string $phase,
        float $durationMs,
        bool $success,
        ?string $exceptionClass = null,
    ): void {
        $timer = self::start($requestId, $phase);
        if ($timer->requestId === null || ! in_array($phase, ['read_permit_wait', 'read_permit_hold'], true)
            || ! is_finite($durationMs) || $durationMs < 0) {
            return;
        }

        try {
            $logger = Log::getFacadeRoot();
            if ($logger === null || ($logger instanceof LogManager && ! is_string(config('logging.default')))) {
                return;
            }

            Log::info('ai.assistant.request_phase_completed', [
                'request_id' => $timer->requestId,
                'phase' => $phase,
                'duration_ms' => round($durationMs, 2),
                'success' => $success,
                'exception_class' => $exceptionClass,
            ]);
        } catch (Throwable) {
        }
    }

    public function finish(?Throwable $failure = null, mixed $result = null): void
    {
        if ($this->finished || $this->requestId === null) {
            return;
        }
        $this->finished = true;
        $duration = round((hrtime(true) - $this->started) / 1_000_000, 2);
        try {
            $logger = Log::getFacadeRoot();
            if ($logger === null || ($logger instanceof LogManager && ! is_string(config('logging.default')))) {
                return;
            }
            $metadata = [
                'request_id' => $this->requestId,
                'phase' => $this->phase,
                'duration_ms' => $duration,
                'success' => $failure === null,
                'exception_class' => $failure === null ? null : $failure::class,
            ];
            if (in_array($this->phase, ['tool', 'stock_read'], true)) {
                $status = is_array($result) ? ($result['status'] ?? null) : null;
                $metadata['outcome'] = in_array($status, ['success', 'empty', 'partial', 'unavailable', 'error', 'failed', 'access_denied', 'forbidden', 'resolved', 'needs_selection'], true) ? $status : null;
                $metadata['reason'] = is_array($result) && ($result['reason'] ?? null) === 'read_timed_out'
                    || ($failure instanceof QueryException && (string) ($failure->errorInfo[0] ?? $failure->getCode()) === '57014')
                    ? 'read_timed_out' : null;
            }
            Log::info('ai.assistant.request_phase_completed', $metadata);
        } catch (Throwable) {
        }
    }
}
