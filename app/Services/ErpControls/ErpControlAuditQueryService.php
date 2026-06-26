<?php

declare(strict_types=1);

namespace App\Services\ErpControls;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class ErpControlAuditQueryService
{
    public function __construct(
        private readonly ImmutableAuditRecorder $auditRecorder,
    ) {
    }

    public function audit(int $organizationId, array $filters): LengthAwarePaginator
    {
        return $this->baseAuditQuery($organizationId, $filters)
            ->orderByDesc('sequence_id')
            ->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters));
    }

    public function auditSummary(int $organizationId, array $filters): array
    {
        $query = $this->baseAuditQuery($organizationId, $filters);

        return [
            'total' => (clone $query)->count(),
            'by_decision' => $this->countBy(clone $query, 'result'),
            'by_risk_level' => $this->countBy(clone $query, 'severity'),
        ];
    }

    public function conflicts(int $organizationId, array $filters): LengthAwarePaginator
    {
        $resolvedIds = $this->resolvedConflictIds($organizationId);

        return $this->baseConflictQuery($organizationId, $filters)
            ->when($resolvedIds !== [], static fn (Builder $query): Builder => $query->whereNotIn('id', $resolvedIds))
            ->orderByDesc('sequence_id')
            ->paginate($this->perPage($filters), ['*'], 'page', $this->page($filters));
    }

    public function conflictsSummary(int $organizationId, array $filters): array
    {
        $resolvedIds = $this->resolvedConflictIds($organizationId);
        $query = $this->baseConflictQuery($organizationId, $filters)
            ->when($resolvedIds !== [], static fn (Builder $query): Builder => $query->whereNotIn('id', $resolvedIds));

        return [
            'open' => (clone $query)->count(),
            'critical' => (clone $query)->where('severity', 'critical')->count(),
            'high' => (clone $query)->where('severity', 'high')->count(),
        ];
    }

    public function resolvedConflictIds(int $organizationId): array
    {
        return ImmutableAuditEvent::query()
            ->forOrganization($organizationId)
            ->where('domain', 'sod')
            ->where('source', 'erp_controls')
            ->where('event_type', 'erp_control.conflict.resolved')
            ->where('subject_type', 'erp_control_conflict')
            ->pluck('subject_id')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    public function findConflict(int $organizationId, string $conflictId): ?ImmutableAuditEvent
    {
        return $this->baseConflictQuery($organizationId, [])
            ->whereKey($conflictId)
            ->first();
    }

    public function resolve(
        ImmutableAuditEvent $conflict,
        int $actorUserId,
        string $decision,
        string $reason,
        ?int $secondApproverUserId = null
    ): ImmutableAuditEvent {
        if (in_array($conflict->id, $this->resolvedConflictIds((int) $conflict->organization_id), true)) {
            throw ValidationException::withMessages([
                'conflict' => [trans_message('erp_controls.messages.conflict_already_resolved')],
            ]);
        }

        return $this->auditRecorder->record(new ImmutableAuditEventData(
            organizationId: (int) $conflict->organization_id,
            projectId: $conflict->project_id,
            domain: 'sod',
            eventType: 'erp_control.conflict.resolved',
            action: 'erp_controls.conflict.resolve',
            source: 'erp_controls',
            result: 'resolved',
            severity: (string) $conflict->severity,
            actorType: 'user',
            actorUserId: $actorUserId,
            subjectType: 'erp_control_conflict',
            subjectId: $conflict->id,
            subjectLabel: $conflict->subject_label,
            reason: $reason,
            relatedSubjects: [[
                'type' => $conflict->subject_type,
                'id' => $conflict->subject_id,
                'label' => $conflict->subject_label,
            ]],
            domainContext: [
                'conflict_event_id' => $conflict->id,
                'operation' => $conflict->action,
                'resolution_decision' => $decision,
                'second_approver_user_id' => $secondApproverUserId,
            ],
        ));
    }

    private function baseAuditQuery(int $organizationId, array $filters): Builder
    {
        return ImmutableAuditEvent::query()
            ->forOrganization($organizationId)
            ->where('domain', 'sod')
            ->where('source', 'erp_controls')
            ->when($filters['domain'] ?? null, static fn (Builder $query, string $domain): Builder => $query->where('domain_context->domain', $domain))
            ->when($filters['operation'] ?? null, static fn (Builder $query, string $operation): Builder => $query->where('action', $operation))
            ->when($filters['decision'] ?? null, static fn (Builder $query, string $decision): Builder => $query->where('result', $decision))
            ->when($filters['actor_user_id'] ?? null, static fn (Builder $query, mixed $actorUserId): Builder => $query->where('actor_user_id', (int) $actorUserId))
            ->when($filters['entity_type'] ?? null, static fn (Builder $query, string $entityType): Builder => $query->where('subject_type', $entityType))
            ->when($filters['entity_id'] ?? null, static fn (Builder $query, mixed $entityId): Builder => $query->where('subject_id', (string) $entityId))
            ->when($filters['date_from'] ?? null, static fn (Builder $query, string $date): Builder => $query->where('occurred_at', '>=', $date))
            ->when($filters['date_to'] ?? null, static fn (Builder $query, string $date): Builder => $query->where('occurred_at', '<=', $date));
    }

    private function baseConflictQuery(int $organizationId, array $filters): Builder
    {
        return ImmutableAuditEvent::query()
            ->forOrganization($organizationId)
            ->where('domain', 'sod')
            ->where('source', 'erp_controls')
            ->where('event_type', 'erp_control.decision.blocked')
            ->where('result', 'blocked')
            ->when($filters['domain'] ?? null, static fn (Builder $query, string $domain): Builder => $query->where('domain_context->domain', $domain))
            ->when($filters['operation'] ?? null, static fn (Builder $query, string $operation): Builder => $query->where('action', $operation))
            ->when($filters['risk_level'] ?? null, static fn (Builder $query, string $riskLevel): Builder => $query->where('severity', $riskLevel))
            ->when($filters['actor_user_id'] ?? null, static fn (Builder $query, mixed $actorUserId): Builder => $query->where('actor_user_id', (int) $actorUserId))
            ->when($filters['entity_type'] ?? null, static fn (Builder $query, string $entityType): Builder => $query->where('subject_type', $entityType))
            ->when($filters['entity_id'] ?? null, static fn (Builder $query, mixed $entityId): Builder => $query->where('subject_id', (string) $entityId));
    }

    private function countBy(Builder $query, string $column): array
    {
        return $query
            ->selectRaw($column.', count(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    private function page(array $filters): int
    {
        return min(max((int) ($filters['page'] ?? 1), 1), 10000);
    }

    private function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 25), 1), 100);
    }
}
