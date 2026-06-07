<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCExchangeMessage;
use App\Models\OneCExchangeOperation;
use App\Services\OneCExchange\Support\OneCExchangePayloadSanitizer;
use App\Services\OneCExchange\Support\OneCExchangeRetryPolicy;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OneCExchangeJournalService
{
    public function __construct(
        private readonly OneCExchangePayloadSanitizer $sanitizer,
        private readonly OneCExchangeRetryPolicy $retryPolicy
    ) {
    }

    public function createOperation(int $organizationId, array $data): OneCExchangeOperation
    {
        $payload = $data['payload'] ?? [];
        $safePayload = is_array($payload) ? $this->sanitizer->preview($payload) : null;

        return OneCExchangeOperation::query()->create([
            'organization_id' => $organizationId,
            'run_id' => $data['run_id'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'mapping_id' => $data['mapping_id'] ?? null,
            'operation_key' => $data['operation_key'] ?? (string) Str::orderedUuid(),
            'correlation_id' => $data['correlation_id'] ?? (string) Str::orderedUuid(),
            'idempotency_key' => $data['idempotency_key'] ?? null,
            'direction' => $data['direction'],
            'scope' => $data['scope'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => isset($data['entity_id']) ? (string) $data['entity_id'] : null,
            'external_id' => $data['external_id'] ?? null,
            'status' => $data['status'] ?? 'pending',
            'accounting_status' => $data['accounting_status'] ?? null,
            'failure_type' => $data['failure_type'] ?? null,
            'retry_count' => (int) ($data['retry_count'] ?? 0),
            'max_attempts' => (int) ($data['max_attempts'] ?? 5),
            'retryable' => (bool) ($data['retryable'] ?? false),
            'next_retry_at' => $data['next_retry_at'] ?? null,
            'source_hash' => $data['source_hash'] ?? null,
            'payload_hash' => $data['payload_hash'] ?? $this->hashPayload($payload),
            'safe_payload_preview' => $safePayload,
            'summary' => $data['summary'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'finished_at' => $data['finished_at'] ?? null,
        ]);
    }

    public function recordAttempt(OneCExchangeOperation $operation, array $data): OneCExchangeMessage
    {
        return DB::transaction(function () use ($operation, $data): OneCExchangeMessage {
            $lockedOperation = OneCExchangeOperation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $this->recordAttemptForLockedOperation($lockedOperation, $data);
        });
    }

    public function list(int $organizationId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $paginator = OneCExchangeOperation::query()
            ->where('organization_id', $organizationId)
            ->withCount('messages')
            ->when($filters['scope'] ?? null, static fn (Builder $query, string $scope): Builder => $query->where('scope', $scope))
            ->when($filters['status'] ?? null, static fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['direction'] ?? null, static fn (Builder $query, string $direction): Builder => $query->where('direction', $direction))
            ->when($filters['entity_type'] ?? null, static fn (Builder $query, string $entityType): Builder => $query->where('entity_type', $entityType))
            ->when($filters['search'] ?? null, static function (Builder $query, string $search): Builder {
                return $query->where(static function (Builder $nested) use ($search): void {
                    $nested
                        ->where('operation_key', 'like', "%{$search}%")
                        ->orWhere('correlation_id', 'like', "%{$search}%")
                        ->orWhere('entity_id', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhere('safe_error_message', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate(min(max($perPage, 1), 100));

        $paginator->getCollection()->transform(fn (OneCExchangeOperation $operation): array => $this->operationPayload($operation));

        return $paginator;
    }

    public function show(int $organizationId, int $operationId): ?array
    {
        $operation = OneCExchangeOperation::query()
            ->where('organization_id', $organizationId)
            ->with(['messages' => static fn ($query) => $query->orderBy('attempt_number')])
            ->find($operationId);

        if (!$operation) {
            return null;
        }

        return [
            ...$this->operationPayload($operation),
            'messages' => $operation->messages->map(fn (OneCExchangeMessage $message): array => $this->messagePayload($message))->all(),
        ];
    }

    public function retry(int $organizationId, int $operationId, ?int $userId): array
    {
        $result = DB::transaction(function () use ($organizationId, $operationId, $userId): array {
            $operation = OneCExchangeOperation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($operationId)
                ->lockForUpdate()
                ->first();

            if (!$operation) {
                return ['operation' => null, 'allowed' => false, 'message' => trans_message('one_c_exchange.operation_not_found')];
            }

            $messagesCount = $operation->messages()->count();
            $attemptNumber = max(1, $messagesCount);
            $decision = $this->retryPolicy->decide(
                status: (string) $operation->status,
                failureType: $operation->failure_type,
                attemptNumber: $attemptNumber,
                maxAttempts: (int) $operation->max_attempts,
                accountingStatus: $operation->accounting_status,
                sourceIsActual: (bool) ($operation->summary['source_is_actual'] ?? true),
                now: new DateTimeImmutable()
            );

            if (!$decision->retryable && !($decision->deadLetter && $operation->status === 'dead_letter')) {
                $operation->setAttribute('messages_count', $messagesCount);

                return ['operation' => $this->operationPayload($operation), 'allowed' => false, 'message' => $decision->reason];
            }

            $this->recordManualRequeueForLockedOperation($operation, $userId);

            return [
                'operation' => null,
                'operation_id' => (int) $operation->id,
                'allowed' => true,
                'message' => trans_message('one_c_exchange.operation_retry_scheduled'),
            ];
        });

        if (!$result['allowed']) {
            return $result;
        }

        return [
            'operation' => $this->show($organizationId, (int) $result['operation_id']),
            'allowed' => true,
            'message' => $result['message'],
        ];
    }

    public function moveToDeadLetter(int $organizationId, int $operationId): ?array
    {
        $operationId = DB::transaction(function () use ($organizationId, $operationId): ?int {
            $operation = OneCExchangeOperation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($operationId)
                ->lockForUpdate()
                ->first();

            if (!$operation) {
                return null;
            }

            $operation->update([
                'status' => 'dead_letter',
                'retryable' => false,
                'next_retry_at' => null,
                'dead_lettered_at' => now(),
                'finished_at' => now(),
            ]);

            $this->recordAttemptForLockedOperation($operation, [
                'status' => 'dead_letter',
                'failure_type' => $operation->failure_type ?? 'manual_dead_letter',
                'safe_error_code' => $operation->safe_error_code ?? 'manual_dead_letter',
                'safe_error_message' => $operation->safe_error_message ?? trans_message('one_c_exchange.safe_errors.manual_dead_letter'),
                'retryable' => false,
            ]);

            return (int) $operation->id;
        });

        if ($operationId === null) {
            return null;
        }

        return $this->show($organizationId, $operationId);
    }

    public function operationPayload(OneCExchangeOperation $operation): array
    {
        return [
            'id' => (int) $operation->id,
            'operation_key' => $operation->operation_key,
            'correlation_id' => $operation->correlation_id,
            'idempotency_key' => $operation->idempotency_key,
            'direction' => $operation->direction,
            'scope' => $operation->scope,
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
            'external_id' => $operation->external_id,
            'status' => $operation->status,
            'accounting_status' => $operation->accounting_status,
            'failure_type' => $operation->failure_type,
            'safe_error_code' => $operation->safe_error_code,
            'safe_error_message' => $operation->safe_error_message,
            'retry_count' => (int) $operation->retry_count,
            'max_attempts' => (int) $operation->max_attempts,
            'retryable' => (bool) $operation->retryable,
            'next_retry_at' => $this->date($operation->next_retry_at),
            'last_attempt_at' => $this->date($operation->last_attempt_at),
            'dead_lettered_at' => $this->date($operation->dead_lettered_at),
            'safe_payload_preview' => $operation->safe_payload_preview,
            'summary' => $operation->summary,
            'messages_count' => (int) ($operation->messages_count ?? $operation->messages()->count()),
            'started_at' => $this->date($operation->started_at),
            'finished_at' => $this->date($operation->finished_at),
            'created_at' => $this->date($operation->created_at),
            'updated_at' => $this->date($operation->updated_at),
        ];
    }

    private function recordAttemptForLockedOperation(OneCExchangeOperation $operation, array $data): OneCExchangeMessage
    {
        $attemptNumber = ((int) $operation->messages()->max('attempt_number')) + 1;
        $status = (string) ($data['status'] ?? 'pending');
        $failureType = isset($data['failure_type']) ? (string) $data['failure_type'] : null;
        $safeError = $this->resolveSafeError($data);
        $decision = $this->retryPolicy->decide(
            status: $status,
            failureType: $failureType,
            attemptNumber: $attemptNumber,
            maxAttempts: (int) $operation->max_attempts,
            accountingStatus: $operation->accounting_status,
            sourceIsActual: (bool) ($data['source_is_actual'] ?? true),
            now: new DateTimeImmutable()
        );
        $nextRetryAt = array_key_exists('next_retry_at', $data) && $data['next_retry_at'] !== null
            ? $this->carbon($data['next_retry_at'])
            : ($decision->nextRetryAt ? CarbonImmutable::instance($decision->nextRetryAt) : null);
        $retryable = array_key_exists('retryable', $data)
            ? (bool) $data['retryable']
            : $decision->retryable;

        $message = OneCExchangeMessage::query()->create([
            'organization_id' => $operation->organization_id,
            'operation_id' => $operation->id,
            'attempt_number' => $attemptNumber,
            'status' => $decision->deadLetter ? 'dead_letter' : $status,
            'failure_type' => $failureType,
            'transport_status' => $data['transport_status'] ?? null,
            'retryable' => $retryable,
            'next_retry_at' => $nextRetryAt,
            'safe_error_code' => $safeError['code'],
            'safe_error_message' => $safeError['message'],
            'request_hash' => $this->hashPayload($data['request'] ?? null),
            'response_hash' => $this->hashPayload($data['response'] ?? null),
            'safe_request_preview' => $this->preview($data['request'] ?? null),
            'safe_response_preview' => $this->preview($data['response'] ?? null),
            'duration_ms' => $data['duration_ms'] ?? null,
            'sent_at' => $data['sent_at'] ?? now(),
            'received_at' => $data['received_at'] ?? ($status === 'pending' || $status === 'queued' ? null : now()),
        ]);

        $isDeadLetter = $decision->deadLetter || $status === 'dead_letter';

        $operation->update([
            'status' => $decision->deadLetter ? 'dead_letter' : $status,
            'failure_type' => $failureType,
            'safe_error_code' => $safeError['code'],
            'safe_error_message' => $safeError['message'],
            'retryable' => $retryable,
            'next_retry_at' => $nextRetryAt,
            'last_attempt_at' => now(),
            'dead_lettered_at' => $isDeadLetter ? now() : $operation->dead_lettered_at,
            'retry_count' => max(0, $attemptNumber - 1),
            'finished_at' => $this->isTerminalStatus($status) ? now() : null,
        ]);

        return $message;
    }

    private function recordManualRequeueForLockedOperation(OneCExchangeOperation $operation, ?int $userId): void
    {
        $attemptNumber = ((int) $operation->messages()->max('attempt_number')) + 1;

        OneCExchangeMessage::query()->create([
            'organization_id' => $operation->organization_id,
            'operation_id' => $operation->id,
            'attempt_number' => $attemptNumber,
            'status' => 'queued',
            'failure_type' => 'manual_retry',
            'retryable' => false,
            'safe_error_code' => null,
            'safe_error_message' => null,
            'safe_request_preview' => [
                'requested_by' => $userId,
                'operation_key' => $operation->operation_key,
            ],
        ]);

        $operation->update([
            'status' => 'queued',
            'retryable' => true,
            'retry_count' => (int) $operation->retry_count + 1,
            'next_retry_at' => now(),
            'dead_lettered_at' => null,
            'finished_at' => null,
            'last_attempt_at' => now(),
        ]);
    }

    private function messagePayload(OneCExchangeMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'attempt_number' => (int) $message->attempt_number,
            'status' => $message->status,
            'failure_type' => $message->failure_type,
            'transport_status' => $message->transport_status,
            'retryable' => (bool) $message->retryable,
            'next_retry_at' => $this->date($message->next_retry_at),
            'safe_error_code' => $message->safe_error_code,
            'safe_error_message' => $message->safe_error_message,
            'safe_request_preview' => $message->safe_request_preview,
            'safe_response_preview' => $message->safe_response_preview,
            'duration_ms' => $message->duration_ms,
            'sent_at' => $this->date($message->sent_at),
            'received_at' => $this->date($message->received_at),
            'created_at' => $this->date($message->created_at),
        ];
    }

    private function resolveSafeError(array $data): array
    {
        if (!isset($data['safe_error_code']) && !isset($data['safe_error_message'])) {
            return ['code' => null, 'message' => null];
        }

        return $this->sanitizer->safeError(
            (string) ($data['safe_error_message'] ?? ''),
            (string) ($data['safe_error_code'] ?? 'exchange_failed')
        );
    }

    private function preview(mixed $payload): ?array
    {
        return is_array($payload) ? $this->sanitizer->preview($payload) : null;
    }

    private function hashPayload(mixed $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function carbon(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['delivered', 'completed', 'accepted', 'posted', 'rejected', 'failed', 'dead_letter', 'cancelled'], true);
    }

    private function date(mixed $value): ?string
    {
        return $value ? $value->toJSON() : null;
    }
}
