<?php

declare(strict_types=1);

namespace App\Services\OneCExchange;

use App\Models\OneCExchangeConflict;
use App\Models\OneCExchangeConflictEvent;
use App\Models\OneCExchangeOperation;
use App\Models\User;
use App\Services\OneCExchange\Support\OneCExchangePayloadSanitizer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class OneCExchangeConflictService
{
    private const OPEN_STATUSES = ['open', 'in_review', 'postponed', 'assigned'];

    private const TERMINAL_STATUSES = ['resolved', 'closed', 'obsolete'];

    private const CONFLICT_FAILURE_CODES = [
        'mapping_missing',
        'duplicate_mapping',
        'business_validation',
        'validation_error',
        'value_mismatch',
        'accounting_conflict',
        'source_outdated',
    ];

    private const DECISION_ACTIONS = [
        'accept_prohelper',
        'accept_one_c',
        'manual_link',
        'postpone',
        'assign',
        'close_obsolete',
        'comment',
    ];

    public function __construct(private readonly OneCExchangePayloadSanitizer $sanitizer)
    {
    }

    public function list(int $organizationId, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $this->syncPendingOperationConflicts($organizationId);

        $query = OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->with(['operation', 'assignedUser', 'resolvedUser'])
            ->withCount('events')
            ->when($this->filter($filters, 'status'), static fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($this->filter($filters, 'scope'), static fn (Builder $query, string $scope): Builder => $query->where('scope', $scope))
            ->when($this->filter($filters, 'severity'), static fn (Builder $query, string $severity): Builder => $query->where('severity', $severity))
            ->when($this->filter($filters, 'entity_type'), static fn (Builder $query, string $entityType): Builder => $query->where('entity_type', $entityType))
            ->when($this->filter($filters, 'conflict_type'), static fn (Builder $query, string $type): Builder => $query->where('conflict_type', $type))
            ->when($this->filter($filters, 'age'), fn (Builder $query, string $age): Builder => $this->applyAgeFilter($query, $age))
            ->when($this->filter($filters, 'search'), static function (Builder $query, string $search): Builder {
                return $query->where(static function (Builder $nested) use ($search): void {
                    $nested
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('entity_id', 'like', "%{$search}%")
                        ->orWhere('external_id', 'like', "%{$search}%")
                        ->orWhere('conflict_key', 'like', "%{$search}%");
                });
            })
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'assigned' THEN 1 WHEN 'postponed' THEN 2 WHEN 'in_review' THEN 3 ELSE 4 END")
            ->orderByRaw('COALESCE(due_at, detected_at) ASC')
            ->orderByDesc('updated_at');

        $paginator = $query->paginate(min(max($perPage, 1), 100));
        $paginator->getCollection()->transform(fn (OneCExchangeConflict $conflict): array => $this->conflictPayload($conflict));

        return $paginator;
    }

    public function show(int $organizationId, int $conflictId): ?array
    {
        $this->syncPendingOperationConflicts($organizationId);

        $conflict = OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->with([
                'operation',
                'assignedUser',
                'resolvedUser',
                'events' => static fn ($query) => $query->with('user')->orderByDesc('created_at')->orderByDesc('id'),
            ])
            ->find($conflictId);

        return $conflict ? $this->conflictPayload($conflict, true) : null;
    }

    public function resolve(int $organizationId, int $conflictId, ?int $userId, array $data): array
    {
        $action = (string) ($data['action'] ?? '');
        $expectedVersion = (int) ($data['expected_version'] ?? 0);

        if (!in_array($action, self::DECISION_ACTIONS, true)) {
            return $this->blocked(trans_message('one_c_exchange.conflict_action_invalid'), 422);
        }

        $validation = $this->validateActionData($organizationId, $action, $data);

        if ($validation !== null) {
            return $validation;
        }

        $result = DB::transaction(function () use ($organizationId, $conflictId, $userId, $data, $action, $expectedVersion): array {
            $conflict = OneCExchangeConflict::query()
                ->where('organization_id', $organizationId)
                ->with('operation')
                ->whereKey($conflictId)
                ->lockForUpdate()
                ->first();

            if (!$conflict) {
                return $this->blocked(trans_message('one_c_exchange.conflict_not_found'), 404);
            }

            if ((int) $conflict->version !== $expectedVersion) {
                return $this->blocked(trans_message('one_c_exchange.conflict_stale'), 409, (int) $conflict->id);
            }

            if (
                $this->operationBecameActual($conflict)
                && !in_array($action, ['close_obsolete', 'comment'], true)
            ) {
                $this->markObsolete($conflict, $userId);

                return $this->blocked(trans_message('one_c_exchange.conflict_stale'), 409, (int) $conflict->id);
            }

            $fromStatus = (string) $conflict->status;
            $update = $this->conflictUpdateForAction($conflict, $action, $userId, $data);

            $conflict->forceFill([
                ...$update,
                'version' => (int) $conflict->version + 1,
            ])->save();

            $this->updateOperationAfterAction($conflict, $action, $userId, $data);
            $this->recordEvent($conflict, $userId, $action, $fromStatus, (string) $conflict->status, $data);

            return [
                'allowed' => true,
                'code' => 200,
                'conflict_id' => (int) $conflict->id,
                'message' => trans_message('one_c_exchange.conflict_action_completed'),
            ];
        });

        if (!$result['allowed']) {
            return $result;
        }

        return [
            ...$result,
            'conflict' => $this->show($organizationId, (int) $result['conflict_id']),
        ];
    }

    public function syncOperationConflict(OneCExchangeOperation $operation): void
    {
        if (!$this->shouldOperationHaveConflict($operation)) {
            $this->obsoleteOpenConflictsForOperation($operation);

            return;
        }

        $this->createOrUpdateConflictFromOperation($operation);
    }

    public function openCount(int $organizationId): int
    {
        $this->syncPendingOperationConflicts($organizationId);

        return OneCExchangeConflict::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', self::OPEN_STATUSES)
            ->count();
    }

    private function syncPendingOperationConflicts(int $organizationId): void
    {
        OneCExchangeOperation::query()
            ->where('organization_id', $organizationId)
            ->where(static function (Builder $query): void {
                $query
                    ->whereIn('status', ['requires_mapping', 'rejected'])
                    ->orWhereIn('failure_type', self::CONFLICT_FAILURE_CODES)
                    ->orWhereIn('safe_error_code', self::CONFLICT_FAILURE_CODES);
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->each(fn (OneCExchangeOperation $operation): OneCExchangeConflict => $this->createOrUpdateConflictFromOperation($operation));
    }

    private function createOrUpdateConflictFromOperation(OneCExchangeOperation $operation): OneCExchangeConflict
    {
        $payload = $this->payloadFromOperation($operation);

        $conflict = OneCExchangeConflict::query()->firstOrNew([
            'organization_id' => $operation->organization_id,
            'conflict_key' => $payload['conflict_key'],
        ]);

        if ($conflict->exists && in_array((string) $conflict->status, self::TERMINAL_STATUSES, true)) {
            return $conflict;
        }

        $payloadForFill = $this->payloadForExistingConflict($conflict, $payload);
        $payloadChanged = $conflict->exists && $this->operationPayloadChanged($conflict, $payloadForFill);

        $conflict->fill([
            ...$payloadForFill,
            'organization_id' => $operation->organization_id,
            'operation_id' => $operation->id,
            'mapping_id' => $operation->mapping_id,
        ]);

        if (!$conflict->exists) {
            $conflict->fill([
                'status' => 'open',
                'version' => 1,
                'detected_at' => $operation->updated_at ?? now(),
            ]);
        } elseif ($payloadChanged) {
            $conflict->version = (int) $conflict->version + 1;
        }

        $conflict->save();

        if ($conflict->events()->doesntExist()) {
            $this->recordEvent($conflict, null, 'created', null, (string) $conflict->status, [
                'operation_id' => (int) $operation->id,
                'safe_error_code' => $operation->safe_error_code,
            ]);
        } elseif ($payloadChanged) {
            $this->recordEvent($conflict, null, 'source_updated', (string) $conflict->status, (string) $conflict->status, [
                'operation_id' => (int) $operation->id,
                'safe_error_code' => $operation->safe_error_code,
            ]);
        }

        return $conflict;
    }

    private function payloadForExistingConflict(OneCExchangeConflict $conflict, array $payload): array
    {
        if (
            !$conflict->exists
            || !in_array((string) $conflict->status, ['postponed', 'assigned'], true)
        ) {
            return $payload;
        }

        unset($payload['due_at']);

        return $payload;
    }

    private function operationPayloadChanged(OneCExchangeConflict $conflict, array $payload): bool
    {
        foreach (['conflict_type', 'severity', 'source_hash', 'payload_hash'] as $field) {
            if ((string) ($conflict->{$field} ?? '') !== (string) ($payload[$field] ?? '')) {
                return true;
            }
        }

        return $this->stableJson($conflict->prohelper_values ?? []) !== $this->stableJson($payload['prohelper_values'] ?? [])
            || $this->stableJson($conflict->one_c_values ?? []) !== $this->stableJson($payload['one_c_values'] ?? [])
            || $this->stableJson($conflict->safe_payload_preview ?? []) !== $this->stableJson($payload['safe_payload_preview'] ?? [])
            || $this->stableJson($conflict->summary ?? []) !== $this->stableJson($payload['summary'] ?? []);
    }

    private function stableJson(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        $this->sortRecursive($value);

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sortRecursive(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursive($item);
            }
        }

        unset($item);
    }

    private function payloadFromOperation(OneCExchangeOperation $operation): array
    {
        $safePayload = is_array($operation->safe_payload_preview)
            ? $this->sanitizer->preview($operation->safe_payload_preview)
            : [];
        $summary = is_array($operation->summary)
            ? $this->sanitizer->preview($operation->summary)
            : [];
        $type = $this->conflictType($operation);
        $prohelperValues = $this->extractSideValues($summary, $safePayload, ['prohelper_values', 'prohelper', 'local_values', 'local']);
        $oneCValues = $this->extractSideValues($summary, $safePayload, ['one_c_values', 'onec_values', '1c_values', 'one_c', 'onec', 'external_values', 'external']);
        $fieldDiffs = $this->extractFieldDifferences($summary, $safePayload);

        if ($fieldDiffs !== []) {
            $prohelperValues = $fieldDiffs['prohelper'];
            $oneCValues = $fieldDiffs['one_c'];
        }

        if ($prohelperValues === []) {
            $prohelperValues = $this->fallbackValues($operation, 'ProHelper');
        }

        if ($oneCValues === []) {
            $oneCValues = $this->fallbackValues($operation, '1C');
        }

        return [
            'conflict_key' => $this->conflictKey($operation),
            'conflict_type' => $type,
            'severity' => $this->severity($operation, $type),
            'scope' => (string) $operation->scope,
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
            'external_id' => $operation->external_id,
            'title' => $this->title($type, $operation),
            'description' => $operation->safe_error_message ?: $this->description($type),
            'source_hash' => $operation->source_hash,
            'payload_hash' => $operation->payload_hash,
            'prohelper_values' => $prohelperValues,
            'one_c_values' => $oneCValues,
            'safe_payload_preview' => $safePayload ?: null,
            'summary' => [
                ...$summary,
                'operation_status' => $operation->status,
                'operation_direction' => $operation->direction,
                'safe_error_code' => $operation->safe_error_code,
            ],
            'due_at' => $this->defaultDueAt($operation, $type),
        ];
    }

    private function shouldOperationHaveConflict(OneCExchangeOperation $operation): bool
    {
        if (in_array((string) $operation->status, ['requires_mapping', 'rejected'], true)) {
            return true;
        }

        return in_array((string) $operation->failure_type, self::CONFLICT_FAILURE_CODES, true)
            || in_array((string) $operation->safe_error_code, self::CONFLICT_FAILURE_CODES, true);
    }

    private function conflictType(OneCExchangeOperation $operation): string
    {
        $code = (string) ($operation->safe_error_code ?: $operation->failure_type);

        return match ($code) {
            'mapping_missing' => 'mapping_missing',
            'duplicate_mapping' => 'duplicate_mapping',
            'business_validation', 'validation_error' => 'business_rule',
            'value_mismatch', 'accounting_conflict' => 'value_mismatch',
            'source_outdated' => 'source_outdated',
            default => match ((string) $operation->status) {
                'requires_mapping' => 'mapping_missing',
                'rejected' => 'business_rule',
                default => 'manual_review',
            },
        };
    }

    private function severity(OneCExchangeOperation $operation, string $type): string
    {
        if ((string) $operation->status === 'rejected' || in_array($type, ['business_rule', 'source_outdated'], true)) {
            return 'critical';
        }

        if ($type === 'mapping_missing' || $type === 'duplicate_mapping') {
            return 'warning';
        }

        return 'warning';
    }

    private function title(string $type, OneCExchangeOperation $operation): string
    {
        $key = "one_c_exchange.conflict_types.{$type}.title";
        $title = trans_message($key);

        if ($title !== $key) {
            return $title;
        }

        return $operation->safe_error_message ?: trans_message('one_c_exchange.conflict_types.manual_review.title');
    }

    private function description(string $type): string
    {
        $key = "one_c_exchange.conflict_types.{$type}.description";
        $description = trans_message($key);

        if ($description !== $key) {
            return $description;
        }

        return trans_message('one_c_exchange.conflict_types.manual_review.description');
    }

    private function conflictKey(OneCExchangeOperation $operation): string
    {
        $fingerprint = $operation->source_hash
            ?: $operation->payload_hash
            ?: implode(':', [(string) $operation->status, (string) $operation->failure_type, (string) $operation->safe_error_code]);

        return sprintf('operation:%d:%s', (int) $operation->id, substr(hash('sha256', $fingerprint), 0, 20));
    }

    private function defaultDueAt(OneCExchangeOperation $operation, string $type): CarbonImmutable
    {
        $base = $operation->updated_at ? CarbonImmutable::parse($operation->updated_at) : CarbonImmutable::now();

        return match ($type) {
            'business_rule', 'source_outdated' => $base->addHours(4),
            'mapping_missing', 'duplicate_mapping' => $base->addDay(),
            default => $base->addHours(8),
        };
    }

    private function extractSideValues(array $summary, array $safePayload, array $keys): array
    {
        foreach ([$summary, $safePayload] as $source) {
            foreach ($keys as $key) {
                $value = $source[$key] ?? null;

                if (is_array($value)) {
                    return $this->sanitizer->preview($value);
                }
            }
        }

        return [];
    }

    private function extractFieldDifferences(array $summary, array $safePayload): array
    {
        $rows = $summary['field_differences']
            ?? $summary['differences']
            ?? $safePayload['field_differences']
            ?? $safePayload['differences']
            ?? null;

        if (!is_array($rows)) {
            return [];
        }

        $prohelper = [];
        $oneC = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $field = (string) ($row['field'] ?? $row['key'] ?? '');

            if ($field === '') {
                continue;
            }

            $prohelper[$field] = $row['prohelper_value'] ?? $row['local_value'] ?? $row['left'] ?? null;
            $oneC[$field] = $row['one_c_value'] ?? $row['onec_value'] ?? $row['external_value'] ?? $row['right'] ?? null;
        }

        return $prohelper === [] && $oneC === [] ? [] : [
            'prohelper' => $this->sanitizer->preview($prohelper),
            'one_c' => $this->sanitizer->preview($oneC),
        ];
    }

    private function fallbackValues(OneCExchangeOperation $operation, string $source): array
    {
        return array_filter([
            'source' => $source,
            'scope' => $operation->scope,
            'entity_type' => $operation->entity_type,
            'entity_id' => $operation->entity_id,
            'external_id' => $operation->external_id,
            'status' => $operation->status,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function validateActionData(int $organizationId, string $action, array $data): ?array
    {
        if (in_array($action, ['accept_prohelper', 'accept_one_c', 'manual_link', 'close_obsolete'], true)) {
            $comment = trim((string) ($data['comment'] ?? ''));

            if ($comment === '') {
                return $this->blocked(trans_message('one_c_exchange.conflict_comment_required'), 422);
            }
        }

        if ($action === 'postpone' && empty($data['postponed_until'])) {
            return $this->blocked(trans_message('one_c_exchange.conflict_postpone_date_required'), 422);
        }

        if ($action === 'assign') {
            $assignedTo = (int) ($data['assigned_to'] ?? 0);

            if ($assignedTo <= 0 || !$this->userBelongsToOrganization($assignedTo, $organizationId)) {
                return $this->blocked(trans_message('one_c_exchange.conflict_assignee_invalid'), 422);
            }
        }

        return null;
    }

    private function conflictUpdateForAction(OneCExchangeConflict $conflict, string $action, ?int $userId, array $data): array
    {
        $now = now();

        return match ($action) {
            'accept_prohelper' => [
                'status' => 'resolved',
                'resolved_by' => $userId,
                'resolved_at' => $now,
                'resolution' => $this->resolutionPayload('prohelper', $data),
                'closed_at' => null,
            ],
            'accept_one_c' => [
                'status' => 'resolved',
                'resolved_by' => $userId,
                'resolved_at' => $now,
                'resolution' => $this->resolutionPayload('one_c', $data),
                'closed_at' => null,
            ],
            'manual_link' => [
                'status' => 'resolved',
                'resolved_by' => $userId,
                'resolved_at' => $now,
                'resolution' => $this->resolutionPayload('manual', $data),
                'closed_at' => null,
            ],
            'postpone' => [
                'status' => 'postponed',
                'postponed_until' => CarbonImmutable::parse((string) $data['postponed_until']),
                'due_at' => CarbonImmutable::parse((string) $data['postponed_until']),
            ],
            'assign' => [
                'status' => 'assigned',
                'assigned_to' => (int) $data['assigned_to'],
            ],
            'close_obsolete' => [
                'status' => 'closed',
                'resolved_by' => $userId,
                'closed_at' => $now,
                'resolution' => $this->resolutionPayload('obsolete', $data),
            ],
            default => [
                'status' => (string) $conflict->status,
            ],
        };
    }

    private function updateOperationAfterAction(OneCExchangeConflict $conflict, string $action, ?int $userId, array $data): void
    {
        $operation = $conflict->operation;

        if (!$operation instanceof OneCExchangeOperation) {
            return;
        }

        $summary = is_array($operation->summary) ? $operation->summary : [];
        $summary['conflict_resolution'] = [
            'conflict_id' => (int) $conflict->id,
            'action' => $action,
            'resolved_by' => $userId,
            'resolved_at' => now()->toJSON(),
        ];

        if (in_array($action, ['accept_prohelper', 'manual_link'], true)) {
            $operation->update([
                'status' => 'queued',
                'retryable' => true,
                'next_retry_at' => now(),
                'dead_lettered_at' => null,
                'finished_at' => null,
                'summary' => $summary,
            ]);

            return;
        }

        if ($action === 'accept_one_c') {
            $operation->update([
                'status' => 'accepted',
                'retryable' => false,
                'next_retry_at' => null,
                'finished_at' => now(),
                'summary' => $summary,
            ]);

            return;
        }

        if ($action === 'close_obsolete') {
            $operation->update([
                'status' => 'cancelled',
                'retryable' => false,
                'next_retry_at' => null,
                'finished_at' => now(),
                'summary' => $summary,
            ]);
        }
    }

    private function resolutionPayload(string $decision, array $data): array
    {
        return [
            'decision' => $decision,
            'comment' => isset($data['comment']) ? mb_substr(trim((string) $data['comment']), 0, 1000) : null,
            'manual_reference' => isset($data['manual_reference']) && is_array($data['manual_reference'])
                ? $this->sanitizer->preview($data['manual_reference'])
                : null,
        ];
    }

    private function operationBecameActual(OneCExchangeConflict $conflict): bool
    {
        $operation = $conflict->operation;

        if (!$operation instanceof OneCExchangeOperation) {
            return false;
        }

        if (
            $conflict->source_hash
            && $operation->source_hash
            && (string) $conflict->source_hash !== (string) $operation->source_hash
        ) {
            return true;
        }

        return !$this->shouldOperationHaveConflict($operation)
            && in_array((string) $conflict->status, self::OPEN_STATUSES, true);
    }

    private function markObsolete(OneCExchangeConflict $conflict, ?int $userId): void
    {
        $fromStatus = (string) $conflict->status;

        $conflict->update([
            'status' => 'obsolete',
            'closed_at' => now(),
            'version' => (int) $conflict->version + 1,
        ]);

        $this->recordEvent($conflict, $userId, 'obsolete_detected', $fromStatus, 'obsolete', [
            'message' => trans_message('one_c_exchange.conflict_stale'),
        ]);
    }

    private function obsoleteOpenConflictsForOperation(OneCExchangeOperation $operation): void
    {
        OneCExchangeConflict::query()
            ->where('organization_id', $operation->organization_id)
            ->where('operation_id', $operation->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->get()
            ->each(function (OneCExchangeConflict $conflict): void {
                $this->markObsolete($conflict, null);
            });
    }

    private function recordEvent(
        OneCExchangeConflict $conflict,
        ?int $userId,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        array $data,
    ): void {
        OneCExchangeConflictEvent::query()->create([
            'organization_id' => $conflict->organization_id,
            'conflict_id' => $conflict->id,
            'user_id' => $userId,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'comment' => isset($data['comment']) ? mb_substr(trim((string) $data['comment']), 0, 1000) : null,
            'payload' => $this->eventPayload($action, $data),
            'created_at' => now(),
        ]);
    }

    private function eventPayload(string $action, array $data): array
    {
        return array_filter([
            'action_label' => trans_message("one_c_exchange.conflict_actions.{$action}"),
            'assigned_to' => isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            'postponed_until' => $data['postponed_until'] ?? null,
            'manual_reference' => isset($data['manual_reference']) && is_array($data['manual_reference'])
                ? $this->sanitizer->preview($data['manual_reference'])
                : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function conflictPayload(OneCExchangeConflict $conflict, bool $withHistory = false): array
    {
        $payload = [
            'id' => (int) $conflict->id,
            'conflict_key' => $conflict->conflict_key,
            'conflict_type' => $conflict->conflict_type,
            'status' => $conflict->status,
            'status_label' => trans_message("one_c_exchange.conflict_statuses.{$conflict->status}"),
            'severity' => $conflict->severity,
            'severity_label' => trans_message("one_c_exchange.conflict_severities.{$conflict->severity}"),
            'scope' => $conflict->scope,
            'entity_type' => $conflict->entity_type,
            'entity_id' => $conflict->entity_id,
            'external_id' => $conflict->external_id,
            'title' => $conflict->title,
            'description' => $conflict->description,
            'operation_id' => $conflict->operation_id ? (int) $conflict->operation_id : null,
            'operation_key' => $conflict->operation?->operation_key,
            'operation_status' => $conflict->operation?->status,
            'operation_direction' => $conflict->operation?->direction,
            'assigned_to' => $this->userPayload($conflict->assignedUser),
            'resolved_by' => $this->userPayload($conflict->resolvedUser),
            'prohelper_values' => $conflict->prohelper_values ?? [],
            'one_c_values' => $conflict->one_c_values ?? [],
            'comparison_fields' => $this->comparisonFields($conflict->prohelper_values ?? [], $conflict->one_c_values ?? []),
            'safe_payload_preview' => $conflict->safe_payload_preview,
            'resolution' => $conflict->resolution,
            'summary' => $conflict->summary,
            'version' => (int) $conflict->version,
            'is_overdue' => $this->isOverdue($conflict),
            'events_count' => (int) ($conflict->events_count ?? $conflict->events()->count()),
            'available_actions' => $this->availableActions($conflict),
            'detected_at' => $this->date($conflict->detected_at),
            'due_at' => $this->date($conflict->due_at),
            'postponed_until' => $this->date($conflict->postponed_until),
            'resolved_at' => $this->date($conflict->resolved_at),
            'closed_at' => $this->date($conflict->closed_at),
            'created_at' => $this->date($conflict->created_at),
            'updated_at' => $this->date($conflict->updated_at),
        ];

        if ($withHistory) {
            $payload['history'] = $conflict->events
                ->map(fn (OneCExchangeConflictEvent $event): array => $this->eventHistoryPayload($event))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function eventHistoryPayload(OneCExchangeConflictEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'action' => $event->action,
            'action_label' => trans_message("one_c_exchange.conflict_actions.{$event->action}"),
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'comment' => $event->comment,
            'payload' => $event->payload,
            'user' => $this->userPayload($event->user),
            'created_at' => $this->date($event->created_at),
        ];
    }

    private function availableActions(OneCExchangeConflict $conflict): array
    {
        if (in_array((string) $conflict->status, self::TERMINAL_STATUSES, true)) {
            return [];
        }

        return [
            $this->actionPayload('accept_prohelper', 'primary', true),
            $this->actionPayload('accept_one_c', 'primary', true),
            $this->actionPayload('manual_link', 'secondary', true),
            $this->actionPayload('postpone', 'secondary', false),
            $this->actionPayload('assign', 'secondary', false),
            $this->actionPayload('close_obsolete', 'danger', true),
            $this->actionPayload('comment', 'secondary', false),
        ];
    }

    private function actionPayload(string $action, string $style, bool $requiresComment): array
    {
        return [
            'type' => $action,
            'label' => trans_message("one_c_exchange.conflict_actions.{$action}"),
            'style' => $style,
            'enabled' => true,
            'requires_comment' => $requiresComment,
            'permission' => 'one_c_exchange.conflicts.resolve',
        ];
    }

    private function comparisonFields(array $prohelperValues, array $oneCValues): array
    {
        $keys = array_values(array_unique([...array_keys($prohelperValues), ...array_keys($oneCValues)]));

        return array_map(fn (string $key): array => [
            'field' => $key,
            'label' => $this->fieldLabel($key),
            'prohelper_value' => $prohelperValues[$key] ?? null,
            'one_c_value' => $oneCValues[$key] ?? null,
            'has_difference' => ($prohelperValues[$key] ?? null) !== ($oneCValues[$key] ?? null),
        ], $keys);
    }

    private function fieldLabel(string $field): string
    {
        $key = "one_c_exchange.conflict_field_labels.{$field}";
        $label = trans_message($key);

        return $label !== $key ? $label : str_replace('_', ' ', $field);
    }

    private function applyAgeFilter(Builder $query, string $age): Builder
    {
        $now = CarbonImmutable::now();

        return match ($age) {
            'overdue' => $query->whereIn('status', self::OPEN_STATUSES)->whereNotNull('due_at')->where('due_at', '<', $now),
            'today' => $query->where('detected_at', '>=', $now->startOfDay()),
            'week' => $query->where('detected_at', '>=', $now->subDays(7)),
            'older' => $query->where('detected_at', '<', $now->subDays(7)),
            default => $query,
        };
    }

    private function userPayload(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    private function userBelongsToOrganization(int $userId, int $organizationId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->whereHas('organizations', static function (Builder $query) use ($organizationId): void {
                $query
                    ->where('organizations.id', $organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->exists();
    }

    private function blocked(string $message, int $code, ?int $conflictId = null): array
    {
        return [
            'allowed' => false,
            'code' => $code,
            'message' => $message,
            'conflict_id' => $conflictId,
            'conflict' => null,
        ];
    }

    private function isOverdue(OneCExchangeConflict $conflict): bool
    {
        return in_array((string) $conflict->status, self::OPEN_STATUSES, true)
            && $conflict->due_at !== null
            && CarbonImmutable::parse($conflict->due_at)->isPast();
    }

    private function filter(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->toJSON();
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value)->toJSON();
        }

        return null;
    }
}
