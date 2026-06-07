<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCExchangeOperation;
use Carbon\CarbonImmutable;
use DateTimeInterface;

use function trans_message;

final class OneCExchangeIncidentRuleResolver
{
    private const SENSITIVE_TERMS = [
        'raw payload',
        'payload',
        'stack trace',
        'trace',
        'token',
        'secret',
        'exception',
        'sql',
        'sqlstate',
        'constraint',
    ];

    private const RULES = [
        'transport_unavailable' => [
            'severity' => 'critical',
            'owner' => 'integration_owner',
            'deadline_minutes' => 30,
            'actions' => ['open_operation', 'retry', 'send_to_1c_specialist', 'move_to_dead_letter'],
            'priority' => 'critical',
        ],
        'dead_letter' => [
            'severity' => 'critical',
            'owner' => 'manual_review_owner',
            'deadline_minutes' => 30,
            'actions' => ['open_operation', 'send_to_1c_specialist', 'retry', 'move_to_dead_letter'],
            'priority' => 'critical',
        ],
        'requires_mapping' => [
            'severity' => 'warning',
            'owner' => 'mapping_owner',
            'deadline_minutes' => 120,
            'actions' => ['open_operation', 'check_mapping', 'move_to_dead_letter'],
            'priority' => 'high',
        ],
        'stale_processing' => [
            'severity' => 'critical',
            'owner' => 'integration_owner',
            'deadline_minutes' => 30,
            'actions' => ['open_operation', 'send_to_1c_specialist', 'move_to_dead_letter'],
            'priority' => 'critical',
        ],
        'overdue_retry' => [
            'severity' => 'warning',
            'owner' => 'integration_owner',
            'deadline_minutes' => 60,
            'actions' => ['open_operation', 'retry', 'send_to_1c_specialist', 'move_to_dead_letter'],
            'priority' => 'high',
        ],
        'business_validation_rejected' => [
            'severity' => 'warning',
            'owner' => 'accounting_owner',
            'deadline_minutes' => 240,
            'actions' => ['open_operation', 'send_to_1c_specialist', 'move_to_dead_letter'],
            'priority' => 'high',
        ],
        'delivery_unconfigured' => [
            'severity' => 'critical',
            'owner' => 'integration_owner',
            'deadline_minutes' => 30,
            'actions' => ['send_to_1c_specialist'],
            'priority' => 'critical',
        ],
    ];

    private const TRANSPORT_CODES = [
        'transport_error',
        'timeout',
        'server_error',
        'network_error',
        'rate_limit',
        'unavailable',
    ];

    private const BUSINESS_CODES = [
        'business_validation',
        'validation_error',
        'rejected',
    ];

    public function resolveSystem(bool $configured, bool $deliveryEnabled, CarbonImmutable $now): ?array
    {
        if ($configured && $deliveryEnabled) {
            return null;
        }

        return $this->buildIncident(
            scenario: 'delivery_unconfigured',
            operation: null,
            now: $now,
            detectedAt: $now,
        );
    }

    public function resolveOperation(
        OneCExchangeOperation $operation,
        CarbonImmutable $now,
        ?string $problemReason = null,
    ): ?array {
        $scenario = $this->scenarioForOperation($operation, $now, $problemReason);

        if ($scenario === null) {
            return null;
        }

        return $this->buildIncident(
            scenario: $scenario,
            operation: $operation,
            now: $now,
            detectedAt: $this->detectedAt($operation, $scenario, $now),
        );
    }

    public function summary(array $incidents, CarbonImmutable $now): array
    {
        $criticalCount = 0;
        $warningCount = 0;
        $infoCount = 0;
        $overdueCount = 0;

        foreach ($incidents as $incident) {
            $severity = (string) ($incident['severity'] ?? 'info');

            if ($severity === 'critical') {
                $criticalCount++;
            } elseif ($severity === 'warning') {
                $warningCount++;
            } else {
                $infoCount++;
            }

            $deadline = $this->dateTime($incident['response_deadline_at'] ?? null);
            if ($deadline !== null && $deadline->lessThan($now)) {
                $overdueCount++;
            }
        }

        return [
            'total_count' => count($incidents),
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'overdue_count' => $overdueCount,
        ];
    }

    private function scenarioForOperation(
        OneCExchangeOperation $operation,
        CarbonImmutable $now,
        ?string $problemReason,
    ): ?string {
        $status = (string) $operation->status;

        if ($problemReason === 'dead_letter' || $status === 'dead_letter') {
            return 'dead_letter';
        }

        if ($problemReason === 'requires_mapping' || $status === 'requires_mapping') {
            return 'requires_mapping';
        }

        if ($problemReason === 'stale_processing' || $this->isStaleProcessing($operation, $now)) {
            return 'stale_processing';
        }

        if ($problemReason === 'overdue_retry' || $this->isOverdueRetry($operation, $now)) {
            return 'overdue_retry';
        }

        $failureKey = $this->failureKey($operation);

        if ($failureKey === 'transport_unconfigured') {
            return 'delivery_unconfigured';
        }

        if ($status === 'rejected' || in_array($failureKey, self::BUSINESS_CODES, true)) {
            return 'business_validation_rejected';
        }

        if ($status === 'failed' || in_array($failureKey, self::TRANSPORT_CODES, true)) {
            return 'transport_unavailable';
        }

        return null;
    }

    private function buildIncident(
        string $scenario,
        ?OneCExchangeOperation $operation,
        CarbonImmutable $now,
        CarbonImmutable $detectedAt,
    ): array {
        $rule = self::RULES[$scenario];
        $deadline = $detectedAt->addMinutes((int) $rule['deadline_minutes']);
        $operationPayload = $operation ? $this->operationPayload($operation) : null;

        return [
            'id' => $operation
                ? "one-c-incident-operation-{$operation->id}-{$scenario}"
                : "one-c-incident-system-{$scenario}",
            'key' => $operation
                ? "operation-{$operation->id}-{$scenario}"
                : "system-{$scenario}",
            'scenario' => $scenario,
            'severity' => $rule['severity'],
            'notification_priority' => $rule['priority'],
            'title' => trans_message("one_c_exchange.incidents.{$scenario}.title"),
            'message' => $operation
                ? trans_message("one_c_exchange.incidents.{$scenario}.message", [
                    'operation' => (string) $operation->id,
                ])
                : trans_message("one_c_exchange.incidents.{$scenario}.message"),
            'owner' => [
                'key' => $rule['owner'],
                'label' => trans_message("one_c_exchange.incidents.owners.{$rule['owner']}"),
            ],
            'next_action' => trans_message("one_c_exchange.incidents.{$scenario}.next_action"),
            'detected_at' => $detectedAt->toJSON(),
            'response_deadline_at' => $deadline->toJSON(),
            'is_overdue' => $deadline->lessThan($now),
            'operation' => $operationPayload,
            'actions' => $this->actions($scenario, $operation, $rule['actions']),
        ];
    }

    private function operationPayload(OneCExchangeOperation $operation): array
    {
        return [
            'id' => (int) $operation->id,
            'operation_key' => (string) $operation->operation_key,
            'correlation_id' => (string) $operation->correlation_id,
            'status' => (string) $operation->status,
            'scope' => (string) $operation->scope,
            'direction' => (string) $operation->direction,
            'entity_type' => $operation->entity_type ? (string) $operation->entity_type : null,
            'entity_id' => $operation->entity_id ? (string) $operation->entity_id : null,
            'external_id' => $operation->external_id ? (string) $operation->external_id : null,
            'failure_type' => $operation->failure_type ? (string) $operation->failure_type : null,
            'retry_count' => (int) $operation->retry_count,
            'max_attempts' => (int) $operation->max_attempts,
            'retryable' => (bool) $operation->retryable,
            'safe_error_code' => $operation->safe_error_code ? (string) $operation->safe_error_code : null,
            'safe_error_message' => $this->safeMessage($operation->safe_error_message),
        ];
    }

    private function actions(string $scenario, ?OneCExchangeOperation $operation, array $types): array
    {
        return array_map(function (string $type) use ($scenario, $operation): array {
            return [
                'type' => $type,
                'label' => trans_message("one_c_exchange.incident_actions.{$type}"),
                'style' => $this->actionStyle($type),
                'enabled' => $this->actionEnabled($type, $scenario, $operation),
                'permission' => $this->actionPermission($type),
            ];
        }, $types);
    }

    private function actionEnabled(string $type, string $scenario, ?OneCExchangeOperation $operation): bool
    {
        if ($type === 'send_to_1c_specialist') {
            return true;
        }

        if ($operation === null) {
            return false;
        }

        if ($type === 'retry') {
            return (bool) $operation->retryable
                || (
                    $operation->status === 'dead_letter'
                    && in_array($this->failureKey($operation), self::TRANSPORT_CODES, true)
                );
        }

        if ($type === 'move_to_dead_letter') {
            return $operation->status !== 'dead_letter';
        }

        if ($type === 'check_mapping') {
            return $scenario === 'requires_mapping';
        }

        return true;
    }

    private function actionStyle(string $type): string
    {
        return match ($type) {
            'retry', 'open_operation' => 'primary',
            'move_to_dead_letter' => 'danger',
            default => 'secondary',
        };
    }

    private function actionPermission(string $type): ?string
    {
        return match ($type) {
            'retry' => 'one_c_exchange.retry',
            'move_to_dead_letter' => 'one_c_exchange.dead_letter.manage',
            default => null,
        };
    }

    private function detectedAt(OneCExchangeOperation $operation, string $scenario, CarbonImmutable $now): CarbonImmutable
    {
        if ($scenario === 'stale_processing') {
            return $this->dateTime($operation->started_at) ?? $now;
        }

        if ($scenario === 'overdue_retry') {
            return $this->dateTime($operation->next_retry_at) ?? $now;
        }

        return $this->dateTime($operation->updated_at)
            ?? $this->dateTime($operation->created_at)
            ?? $now;
    }

    private function isStaleProcessing(OneCExchangeOperation $operation, CarbonImmutable $now): bool
    {
        if ($operation->status !== 'processing' || $operation->started_at === null) {
            return false;
        }

        $timeoutMinutes = max(1, (int) config('one_c_exchange.delivery.processing_timeout_minutes', 15));

        return $this->dateTime($operation->started_at)?->lessThanOrEqualTo($now->subMinutes($timeoutMinutes)) ?? false;
    }

    private function isOverdueRetry(OneCExchangeOperation $operation, CarbonImmutable $now): bool
    {
        if ($operation->status !== 'retry_scheduled' || $operation->next_retry_at === null) {
            return false;
        }

        return $this->dateTime($operation->next_retry_at)?->lessThan($now) ?? false;
    }

    private function failureKey(OneCExchangeOperation $operation): ?string
    {
        $safeCode = $operation->safe_error_code ? (string) $operation->safe_error_code : null;
        $failureType = $operation->failure_type ? (string) $operation->failure_type : null;

        return $safeCode ?: $failureType;
    }

    private function safeMessage(mixed $message): ?string
    {
        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        $normalized = mb_strtolower($message);

        foreach (self::SENSITIVE_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                return trans_message('one_c_exchange.incidents.safe_message_hidden');
            }
        }

        return $message;
    }

    private function dateTime(mixed $value): ?CarbonImmutable
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
}
