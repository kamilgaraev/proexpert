<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequestEvent;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MdmChangeRequestService
{
    private const TERMINAL_STATUSES = [
        MdmChangeRequest::STATUS_REJECTED,
        MdmChangeRequest::STATUS_APPLIED,
        MdmChangeRequest::STATUS_FAILED,
        MdmChangeRequest::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly MdmEntityRegistry $registry,
        private readonly MdmRecordService $recordService,
        private readonly MdmChangeLogService $changeLogService,
        private readonly MdmEntityGovernanceRegistry $governanceRegistry,
        private readonly MdmDiffService $diffService,
        private readonly MdmImpactAnalysisService $impactAnalysisService,
        private readonly MdmOneCLockService $oneCLockService,
        private readonly MdmDomainChangeApplier $domainChangeApplier
    ) {}

    public function preview(int $organizationId, array $payload): array
    {
        $prepared = $this->preparePayload($organizationId, $payload);

        return [
            'entity_type' => $prepared['entity_type'],
            'entity_id' => $prepared['entity_id'],
            'action' => $prepared['action'],
            'title' => $prepared['title'],
            'current_values' => $prepared['current_values'],
            'proposed_values' => $prepared['proposed_values'],
            'diff' => $prepared['diff'],
            'impact' => $prepared['impact'],
            'one_c_lock' => $prepared['one_c_lock'],
            'validation' => $prepared['validation'],
            'field_policy' => $this->governanceRegistry->publicPolicy($prepared['entity_type']),
            'expected_record_version' => $prepared['expected_record_version'],
            'payload_hash' => $prepared['payload_hash'],
        ];
    }

    public function createDraft(int $organizationId, array $payload, ?int $userId): MdmChangeRequest
    {
        $prepared = $this->preparePayload($organizationId, $payload);
        $duplicate = $this->findDuplicate($organizationId, $prepared['idempotency_key'], $prepared['payload_hash']);

        if ($duplicate instanceof MdmChangeRequest) {
            return $duplicate->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
        }

        try {
            return DB::transaction(function () use ($organizationId, $prepared, $payload, $userId): MdmChangeRequest {
                $changeRequest = MdmChangeRequest::query()->create([
                    'organization_id' => $organizationId,
                    'mdm_record_id' => $prepared['mdm_record_id'],
                    'entity_type' => $prepared['entity_type'],
                    'entity_id' => $prepared['entity_id'],
                    'action' => $prepared['action'],
                    'status' => MdmChangeRequest::STATUS_DRAFT,
                    'priority' => $prepared['priority'],
                    'title' => $prepared['title'],
                    'reason' => Arr::get($payload, 'reason'),
                    'business_justification' => Arr::get($payload, 'business_justification'),
                    'current_values' => $prepared['current_values'],
                    'proposed_values' => $prepared['proposed_values'],
                    'diff' => $prepared['diff'],
                    'field_policy_version' => $prepared['field_policy_version'],
                    'impact_snapshot' => $prepared['impact'],
                    'validation_snapshot' => $prepared['validation'],
                    'one_c_lock_summary' => $prepared['one_c_lock'],
                    'requested_by_user_id' => $userId,
                    'owner_user_id' => $prepared['owner_user_id'],
                    'expected_record_version' => $prepared['expected_record_version'],
                    'idempotency_key' => $prepared['idempotency_key'],
                    'payload_hash' => $prepared['payload_hash'],
                ]);

                $this->event($changeRequest, 'created', $userId, null, MdmChangeRequest::STATUS_DRAFT);

                return $changeRequest->refresh()->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
            });
        } catch (QueryException $exception) {
            $duplicate = $this->findDuplicate($organizationId, $prepared['idempotency_key'], $prepared['payload_hash']);
            if ($duplicate instanceof MdmChangeRequest) {
                return $duplicate->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
            }

            throw $exception;
        }
    }

    public function updateDraft(MdmChangeRequest $changeRequest, array $payload, ?int $userId): MdmChangeRequest
    {
        $this->assertStatus($changeRequest, [MdmChangeRequest::STATUS_DRAFT]);

        $prepared = $this->preparePayload((int) $changeRequest->organization_id, array_merge([
            'entity_type' => $changeRequest->entity_type,
            'entity_id' => $changeRequest->entity_id,
            'action' => $changeRequest->action,
        ], $payload));

        return DB::transaction(function () use ($changeRequest, $prepared, $payload, $userId): MdmChangeRequest {
            $changeRequest->fill([
                'mdm_record_id' => $prepared['mdm_record_id'],
                'priority' => $prepared['priority'],
                'title' => $prepared['title'],
                'reason' => Arr::get($payload, 'reason', $changeRequest->reason),
                'business_justification' => Arr::get($payload, 'business_justification', $changeRequest->business_justification),
                'current_values' => $prepared['current_values'],
                'proposed_values' => $prepared['proposed_values'],
                'diff' => $prepared['diff'],
                'field_policy_version' => $prepared['field_policy_version'],
                'impact_snapshot' => $prepared['impact'],
                'validation_snapshot' => $prepared['validation'],
                'one_c_lock_summary' => $prepared['one_c_lock'],
                'owner_user_id' => $prepared['owner_user_id'],
                'expected_record_version' => $prepared['expected_record_version'],
                'payload_hash' => $prepared['payload_hash'],
            ]);
            $changeRequest->save();

            $this->event($changeRequest, 'updated', $userId, MdmChangeRequest::STATUS_DRAFT, MdmChangeRequest::STATUS_DRAFT);

            return $changeRequest->refresh()->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
        });
    }

    public function submitDraft(MdmChangeRequest $changeRequest, ?int $userId, ?string $comment = null): MdmChangeRequest
    {
        $this->assertStatus($changeRequest, [MdmChangeRequest::STATUS_DRAFT]);
        $this->assertNoBlockers($changeRequest);

        return $this->transition($changeRequest, MdmChangeRequest::STATUS_SUBMITTED, 'submitted', $userId, $comment, [
            'submitted_at' => now(),
        ]);
    }

    public function startReview(MdmChangeRequest $changeRequest, ?int $userId, ?string $comment = null): MdmChangeRequest
    {
        $this->assertStatus($changeRequest, [MdmChangeRequest::STATUS_SUBMITTED]);

        return $this->transition($changeRequest, MdmChangeRequest::STATUS_UNDER_REVIEW, 'review_started', $userId, $comment, [
            'under_review_at' => now(),
        ]);
    }

    public function approve(MdmChangeRequest $changeRequest, ?int $userId, ?string $note): MdmChangeRequest
    {
        $this->assertStatus($changeRequest, [MdmChangeRequest::STATUS_SUBMITTED, MdmChangeRequest::STATUS_UNDER_REVIEW]);
        $this->assertNoBlockers($changeRequest);

        return $this->transition($changeRequest, MdmChangeRequest::STATUS_APPROVED, 'approved', $userId, $note, [
            'approved_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $userId,
            'approver_user_id' => $userId,
            'review_note' => $note,
        ]);
    }

    public function reject(MdmChangeRequest $changeRequest, ?int $userId, ?string $note): MdmChangeRequest
    {
        $this->assertStatus($changeRequest, [
            MdmChangeRequest::STATUS_SUBMITTED,
            MdmChangeRequest::STATUS_UNDER_REVIEW,
            MdmChangeRequest::STATUS_APPROVED,
        ]);

        return $this->transition($changeRequest, MdmChangeRequest::STATUS_REJECTED, 'rejected', $userId, $note, [
            'rejected_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $userId,
            'review_note' => $note,
        ]);
    }

    public function applyApproved(MdmChangeRequest $changeRequest, ?int $userId, ?string $note = null): MdmChangeRequest
    {
        if ($changeRequest->status === MdmChangeRequest::STATUS_APPLIED) {
            return $this->detail($changeRequest);
        }

        $this->assertStatus($changeRequest, [MdmChangeRequest::STATUS_APPROVED]);

        return DB::transaction(function () use ($changeRequest, $userId, $note): MdmChangeRequest {
            $changeRequest = MdmChangeRequest::query()
                ->whereKey($changeRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($changeRequest->status === MdmChangeRequest::STATUS_APPLIED) {
                return $changeRequest->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
            }

            $this->assertStatus($changeRequest, [MdmChangeRequest::STATUS_APPROVED]);
            $this->refreshSnapshots($changeRequest);
            $this->assertNoBlockers($changeRequest);
            $this->assertFreshPayload($changeRequest);

            $beforeStatus = (string) $changeRequest->status;
            $target = $this->domainChangeApplier->apply($changeRequest);
            $record = $this->recordService->syncModel($target, (string) $changeRequest->entity_type, $userId);

            $changeRequest->fill([
                'mdm_record_id' => $record->id,
                'entity_id' => (int) $target->getKey(),
                'status' => MdmChangeRequest::STATUS_APPLIED,
                'executor_user_id' => $userId,
                'applied_at' => now(),
                'apply_note' => $note,
                'apply_result' => [
                    'entity_type' => $changeRequest->entity_type,
                    'entity_id' => (int) $target->getKey(),
                    'mdm_record_id' => $record->id,
                    'record_version' => (int) $record->version,
                ],
            ]);
            $changeRequest->save();

            $this->changeLogService->log(
                (int) $changeRequest->organization_id,
                (string) $changeRequest->entity_type,
                (int) $target->getKey(),
                'change_request_applied',
                $changeRequest->current_values,
                $target->getAttributes(),
                $userId,
                ['change_request_id' => $changeRequest->id, 'note' => $note],
                $record
            );
            $this->event($changeRequest, 'applied', $userId, $beforeStatus, MdmChangeRequest::STATUS_APPLIED, $note);

            return $changeRequest->refresh()->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
        });
    }

    public function cancel(MdmChangeRequest $changeRequest, ?int $userId, ?string $reason): MdmChangeRequest
    {
        if (in_array($changeRequest->status, self::TERMINAL_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => [trans_message('mdm.validation.change_request_terminal')],
            ]);
        }

        return $this->transition($changeRequest, MdmChangeRequest::STATUS_CANCELLED, 'cancelled', $userId, $reason, [
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $userId,
            'cancel_reason' => $reason,
        ]);
    }

    public function submit(
        int $organizationId,
        string $entityType,
        string $action,
        array $proposedValues,
        ?int $entityId,
        ?int $userId
    ): MdmChangeRequest {
        $draft = $this->createDraft($organizationId, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'proposed_values' => $proposedValues,
        ], $userId);

        return $draft->status === MdmChangeRequest::STATUS_DRAFT
            ? $this->submitDraft($draft, $userId)
            : $draft;
    }

    public function list(int $organizationId, array $filters = []): LengthAwarePaginator
    {
        return MdmChangeRequest::query()
            ->with(['requestedBy:id,name,email', 'owner:id,name,email', 'approver:id,name,email', 'executor:id,name,email'])
            ->where('organization_id', $organizationId)
            ->when(Arr::get($filters, 'entity_type'), static fn ($query, $value) => $query->where('entity_type', $value))
            ->when(Arr::get($filters, 'status'), static fn ($query, $value) => $query->where('status', $value))
            ->when(Arr::get($filters, 'priority'), static fn ($query, $value) => $query->where('priority', $value))
            ->when(Arr::get($filters, 'q'), function ($query, mixed $value): void {
                $search = '%'.(string) $value.'%';
                $query->where(function ($nested) use ($search): void {
                    $nested->where('title', 'like', $search)
                        ->orWhere('entity_type', 'like', $search);
                });
            })
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 when 'low' then 3 else 4 end")
            ->orderByDesc('created_at')
            ->paginate(min(max((int) Arr::get($filters, 'per_page', 25), 1), 100));
    }

    public function detail(MdmChangeRequest $changeRequest): MdmChangeRequest
    {
        return $changeRequest->load([
            'requestedBy:id,name,email',
            'owner:id,name,email',
            'approver:id,name,email',
            'executor:id,name,email',
            'events.actor:id,name,email',
        ]);
    }

    public function refreshImpact(MdmChangeRequest $changeRequest): MdmChangeRequest
    {
        $this->refreshSnapshots($changeRequest);

        return $this->detail($changeRequest);
    }

    public function assignOwner(MdmRecord $record, ?int $ownerUserId, ?int $changedByUserId): MdmRecord
    {
        $before = $record->getAttributes();
        $record->update([
            'owner_user_id' => $ownerUserId,
            'version' => ((int) $record->version) + 1,
        ]);

        $this->changeLogService->log(
            (int) $record->organization_id,
            $record->entity_type,
            (int) $record->entity_id,
            'owner_assigned',
            $before,
            $record->getAttributes(),
            $changedByUserId,
            ['owner_user_id' => $ownerUserId],
            $record
        );

        return $record->refresh();
    }

    private function preparePayload(int $organizationId, array $payload): array
    {
        $entityType = (string) Arr::get($payload, 'entity_type');
        $action = (string) Arr::get($payload, 'action', 'update');
        $entityId = Arr::get($payload, 'entity_id');
        $entityId = $entityId === null || $entityId === '' ? null : (int) $entityId;

        if (! $this->governanceRegistry->has($entityType)) {
            throw ValidationException::withMessages([
                'entity_type' => [trans_message('mdm.errors.entity_not_supported')],
            ]);
        }

        if ($action === 'create' && ! $this->registry->supportsCreate($entityType)) {
            throw ValidationException::withMessages([
                'action' => [trans_message('mdm.validation.create_not_supported')],
            ]);
        }

        if ($action === 'update' && $entityId === null) {
            throw ValidationException::withMessages([
                'entity_id' => [trans_message('mdm.validation.entity_required_for_update')],
            ]);
        }

        $currentValues = [];
        $model = null;
        if ($entityId !== null) {
            $model = $this->registry->query($entityType, $organizationId)->findOrFail($entityId);
            $currentValues = $model->getAttributes();
        }

        $diffData = $this->diffService->build($entityType, $currentValues, (array) Arr::get($payload, 'proposed_values', []));
        $this->assertHasChanges($entityType, $diffData);
        $this->assertScopedReferences($organizationId, $entityType, $diffData['proposed_values'], $entityId);

        $impact = $this->impactAnalysisService->analyze($organizationId, $entityType, $entityId, $diffData['diff']);
        $oneCLock = $this->oneCLockService->summarize($organizationId, $entityType, $entityId, $diffData['diff']);
        $blockers = array_values(array_merge($diffData['blockers'], $impact['blockers'] ?? [], $oneCLock['blockers'] ?? []));
        $warnings = array_values(array_merge($diffData['warnings'], $impact['warnings'] ?? [], $oneCLock['warnings'] ?? []));
        $record = $entityId === null ? null : $this->findRecord($organizationId, $entityType, $entityId, $model);

        $normalized = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'proposed_values' => $diffData['proposed_values'],
        ];

        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'title' => $this->title($entityType, $model, $action),
            'priority' => $this->priority((string) Arr::get($payload, 'priority', $blockers === [] ? 'normal' : 'high')),
            'current_values' => $currentValues,
            'proposed_values' => $diffData['proposed_values'],
            'diff' => $diffData['diff'],
            'impact' => $impact,
            'one_c_lock' => $oneCLock,
            'validation' => [
                'blockers' => $blockers,
                'warnings' => $warnings,
                'has_blockers' => $blockers !== [],
            ],
            'field_policy_version' => $this->governanceRegistry->publicPolicy($entityType)['policy_version'] ?? '2026-06-21-mvp',
            'mdm_record_id' => $record?->id,
            'owner_user_id' => $record?->owner_user_id,
            'expected_record_version' => $record?->version,
            'payload_hash' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR)),
            'idempotency_key' => Arr::get($payload, 'idempotency_key'),
        ];
    }

    private function refreshSnapshots(MdmChangeRequest $changeRequest): void
    {
        $prepared = $this->preparePayload((int) $changeRequest->organization_id, [
            'entity_type' => $changeRequest->entity_type,
            'entity_id' => $changeRequest->entity_id,
            'action' => $changeRequest->action,
            'proposed_values' => $changeRequest->proposed_values ?? [],
            'priority' => $changeRequest->priority,
        ]);

        $changeRequest->fill([
            'current_values' => $prepared['current_values'],
            'diff' => $prepared['diff'],
            'impact_snapshot' => $prepared['impact'],
            'validation_snapshot' => $prepared['validation'],
            'one_c_lock_summary' => $prepared['one_c_lock'],
        ]);
    }

    private function findRecord(int $organizationId, string $entityType, int $entityId, ?Model $model): ?MdmRecord
    {
        $record = MdmRecord::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->first();

        if ($record instanceof MdmRecord || ! $model instanceof Model) {
            return $record;
        }

        return $this->recordService->syncModel($model, $entityType);
    }

    private function findDuplicate(int $organizationId, mixed $idempotencyKey, string $payloadHash): ?MdmChangeRequest
    {
        $query = MdmChangeRequest::query()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', self::TERMINAL_STATUSES);

        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $duplicate = (clone $query)->where('idempotency_key', $idempotencyKey)->first();
            if ($duplicate instanceof MdmChangeRequest) {
                return $duplicate;
            }
        }

        return $query->where('payload_hash', $payloadHash)->first();
    }

    private function transition(
        MdmChangeRequest $changeRequest,
        string $status,
        string $eventType,
        ?int $userId,
        ?string $comment,
        array $attributes = []
    ): MdmChangeRequest {
        return DB::transaction(function () use ($changeRequest, $status, $eventType, $userId, $comment, $attributes): MdmChangeRequest {
            $locked = MdmChangeRequest::query()->whereKey($changeRequest->getKey())->lockForUpdate()->firstOrFail();
            $before = (string) $locked->status;
            $locked->fill(array_merge($attributes, ['status' => $status]));
            $locked->save();

            $this->event($locked, $eventType, $userId, $before, $status, $comment);

            return $locked->refresh()->load(['requestedBy', 'owner', 'approver', 'executor', 'events']);
        });
    }

    private function event(
        MdmChangeRequest $changeRequest,
        string $eventType,
        ?int $userId,
        ?string $beforeStatus,
        ?string $afterStatus,
        ?string $comment = null,
        ?array $metadata = null
    ): void {
        MdmChangeRequestEvent::query()->create([
            'organization_id' => $changeRequest->organization_id,
            'change_request_id' => $changeRequest->id,
            'event_type' => $eventType,
            'actor_user_id' => $userId,
            'before_status' => $beforeStatus,
            'after_status' => $afterStatus,
            'comment' => $comment,
            'metadata' => $metadata,
        ]);
    }

    private function assertStatus(MdmChangeRequest $changeRequest, array $allowed): void
    {
        if (! in_array($changeRequest->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => [trans_message('mdm.validation.change_request_status')],
            ]);
        }
    }

    private function assertNoBlockers(MdmChangeRequest $changeRequest): void
    {
        $blockers = $changeRequest->validation_snapshot['blockers'] ?? [];

        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'blockers' => [trans_message('mdm.validation.change_request_has_blockers')],
            ]);
        }
    }

    private function assertFreshPayload(MdmChangeRequest $changeRequest): void
    {
        if ($changeRequest->action === 'create' || $changeRequest->entity_id === null || $changeRequest->expected_record_version === null) {
            return;
        }

        $record = MdmRecord::query()
            ->where('organization_id', $changeRequest->organization_id)
            ->where('entity_type', $changeRequest->entity_type)
            ->where('entity_id', $changeRequest->entity_id)
            ->first();

        if (! $record instanceof MdmRecord || (int) $record->version === (int) $changeRequest->expected_record_version) {
            return;
        }

        throw ValidationException::withMessages([
            'expected_record_version' => [trans_message('mdm.validation.stale_payload')],
        ]);
    }

    private function assertHasChanges(string $entityType, array $diffData): void
    {
        if (($diffData['diff'] ?? []) !== [] || ($diffData['blockers'] ?? []) !== []) {
            return;
        }

        throw ValidationException::withMessages([
            'proposed_values' => [
                trans_message('mdm.validation.no_supported_fields', [
                    'entity' => $this->registry->get($entityType)['title'],
                ]),
            ],
        ]);
    }

    private function assertScopedReferences(int $organizationId, string $entityType, array $values, ?int $entityId): void
    {
        foreach ($this->registry->referenceFields($entityType) as $field => $referenceEntityType) {
            if (! array_key_exists($field, $values) || $values[$field] === null || $values[$field] === '') {
                continue;
            }

            $referenceId = (int) $values[$field];

            if ($field === 'parent_id' && $entityId !== null && $referenceId === $entityId) {
                throw ValidationException::withMessages([
                    "proposed_values.{$field}" => [trans_message('mdm.validation.parent_self_reference')],
                ]);
            }

            $exists = $this->registry
                ->query((string) $referenceEntityType, $organizationId)
                ->whereKey($referenceId)
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    "proposed_values.{$field}" => [
                        trans_message('mdm.validation.reference_not_found', [
                            'field' => $this->registry->fieldLabel($entityType, (string) $field),
                        ]),
                    ],
                ]);
            }
        }
    }

    private function title(string $entityType, ?Model $model, string $action): string
    {
        $entityTitle = (string) $this->registry->get($entityType)['title'];
        $display = $model instanceof Model ? $this->registry->displayName($model, $entityType) : null;

        return $display
            ? trans_message('mdm.change_request_title.with_entity', ['entity' => $entityTitle, 'name' => $display])
            : trans_message('mdm.change_request_title.new_entity', ['entity' => $entityTitle, 'action' => $action]);
    }

    private function priority(string $priority): string
    {
        return in_array($priority, ['low', 'normal', 'high', 'urgent'], true) ? $priority : 'normal';
    }
}
