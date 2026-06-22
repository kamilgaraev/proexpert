<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\SourceAdapters;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\Models\OneCExchangeMessage;
use App\Models\OneCExchangeOperation;

final class OneCExchangeAuditAdapter
{
    public function __construct(
        private readonly ImmutableAuditRecorder $recorder,
    ) {}

    public function recordOperationCreated(OneCExchangeOperation $operation): void
    {
        $this->recorder->record(new ImmutableAuditEventData(
            organizationId: (int) $operation->organization_id,
            domain: 'one_c_exchange',
            eventType: 'one_c_exchange.operation.created',
            action: 'created',
            source: 'one_c_exchange.operations',
            result: $this->result((string) $operation->status),
            severity: $this->severity((string) $operation->status),
            actorType: $operation->created_by === null ? 'system' : 'user',
            actorUserId: $operation->created_by === null ? null : (int) $operation->created_by,
            sourceModel: OneCExchangeOperation::class,
            sourceTable: $operation->getTable(),
            sourceEventId: (string) $operation->id,
            correlationId: $operation->correlation_id,
            idempotencyKey: $operation->idempotency_key,
            subjectType: OneCExchangeOperation::class,
            subjectId: $operation->id,
            subjectLabel: $operation->operation_key,
            afterState: $this->operationState($operation),
            domainContext: [
                'safe_payload_preview' => $operation->safe_payload_preview ?? [],
                'summary' => $operation->summary ?? [],
            ],
            occurredAt: $operation->created_at,
        ));
    }

    public function recordMessage(
        OneCExchangeOperation $operation,
        OneCExchangeMessage $message,
        string $eventType,
        ?int $actorUserId = null
    ): void {
        $this->recorder->record(new ImmutableAuditEventData(
            organizationId: (int) $operation->organization_id,
            domain: 'one_c_exchange',
            eventType: 'one_c_exchange.'.$eventType,
            action: $eventType,
            source: 'one_c_exchange.messages',
            result: $this->result((string) $message->status),
            severity: $this->severity((string) $message->status),
            actorType: $actorUserId === null ? 'system' : 'user',
            actorUserId: $actorUserId,
            sourceModel: OneCExchangeMessage::class,
            sourceTable: $message->getTable(),
            sourceEventId: (string) $message->id,
            correlationId: $operation->correlation_id,
            idempotencyKey: $operation->idempotency_key,
            subjectType: OneCExchangeOperation::class,
            subjectId: $operation->id,
            subjectLabel: $operation->operation_key,
            relatedSubjects: [
                [
                    'type' => OneCExchangeMessage::class,
                    'id' => (string) $message->id,
                    'label' => (string) $message->attempt_number,
                ],
            ],
            afterState: [
                'operation' => $this->operationState($operation),
                'message' => $this->messageState($message),
            ],
            domainContext: [
                'safe_request_preview' => $message->safe_request_preview ?? [],
                'safe_response_preview' => $message->safe_response_preview ?? [],
            ],
            occurredAt: $message->created_at,
        ));
    }

    private function operationState(OneCExchangeOperation $operation): array
    {
        return [
            'operation_key' => $operation->operation_key,
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
            'retry_count' => $operation->retry_count,
            'max_attempts' => $operation->max_attempts,
            'retryable' => $operation->retryable,
            'next_retry_at' => $operation->next_retry_at,
            'last_attempt_at' => $operation->last_attempt_at,
            'dead_lettered_at' => $operation->dead_lettered_at,
            'source_hash' => $operation->source_hash,
            'payload_hash' => $operation->payload_hash,
        ];
    }

    private function messageState(OneCExchangeMessage $message): array
    {
        return [
            'attempt_number' => $message->attempt_number,
            'status' => $message->status,
            'failure_type' => $message->failure_type,
            'transport_status' => $message->transport_status,
            'retryable' => $message->retryable,
            'next_retry_at' => $message->next_retry_at,
            'safe_error_code' => $message->safe_error_code,
            'safe_error_message' => $message->safe_error_message,
            'request_hash' => $message->request_hash,
            'response_hash' => $message->response_hash,
            'duration_ms' => $message->duration_ms,
            'sent_at' => $message->sent_at,
            'received_at' => $message->received_at,
        ];
    }

    private function result(string $status): string
    {
        return in_array($status, ['failed', 'dead_letter', 'rejected', 'cancelled'], true) ? 'failure' : 'success';
    }

    private function severity(string $status): string
    {
        return match ($status) {
            'failed', 'dead_letter', 'rejected', 'cancelled' => 'warning',
            default => 'info',
        };
    }
}
