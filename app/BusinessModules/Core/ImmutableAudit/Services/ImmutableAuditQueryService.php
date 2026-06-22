<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventFilters;
use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ImmutableAuditQueryService
{
    public function __construct(
        private readonly ImmutableAuditIntegrityService $integrity,
    ) {}

    public function paginate(ImmutableAuditEventFilters $filters): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->select([
                'id',
                'sequence_id',
                'organization_id',
                'project_id',
                'domain',
                'event_type',
                'action',
                'result',
                'severity',
                'occurred_at',
                'recorded_at',
                'actor_type',
                'actor_user_id',
                'source',
                'source_model',
                'source_table',
                'source_event_id',
                'correlation_id',
                'subject_type',
                'subject_id',
                'subject_label',
                'sensitive_fields',
                'chain_scope',
                'chain_version',
                'integrity_status',
                'retention_until',
                'created_at',
            ])
            ->orderByDesc('sequence_id')
            ->paginate($filters->perPage, ['*'], 'page', $filters->page);
    }

    public function summary(ImmutableAuditEventFilters $filters): array
    {
        $query = $this->baseQuery($filters);

        return [
            'total' => (clone $query)->count(),
            'by_result' => $this->countBy(clone $query, 'result'),
            'by_severity' => $this->countBy(clone $query, 'severity'),
            'by_integrity_status' => $this->countBy(clone $query, 'integrity_status'),
        ];
    }

    public function findForOrganization(int $organizationId, string $eventId): ?ImmutableAuditEvent
    {
        return ImmutableAuditEvent::query()
            ->forOrganization($organizationId)
            ->whereKey($eventId)
            ->first();
    }

    public function exportRows(ImmutableAuditEventFilters $filters, int $limit = 10000): Collection
    {
        return $this->baseQuery($filters)
            ->orderByDesc('sequence_id')
            ->limit($limit)
            ->get();
    }

    public function verifyEvent(ImmutableAuditEvent $event): array
    {
        $previousHash = ImmutableAuditEvent::query()
            ->where('chain_scope', $event->chain_scope)
            ->where('sequence_id', '<', $event->sequence_id)
            ->orderByDesc('sequence_id')
            ->value('record_hash');

        $result = $this->integrity->verifyEvent($event);
        $result['link_valid'] = $event->previous_hash === $previousHash;
        $result['expected_previous_hash'] = $previousHash;
        $result['actual_previous_hash'] = $event->previous_hash;
        $result['valid'] = $result['payload_valid'] && $result['record_valid'] && $result['link_valid'];

        return $result;
    }

    public function verifyChain(ImmutableAuditEventFilters $filters): array
    {
        $events = $this->baseQuery($filters)
            ->orderBy('sequence_id')
            ->limit(1000)
            ->get();

        $checked = 0;
        $issues = [];

        foreach ($events as $event) {
            $checked++;
            $result = $this->verifyEvent($event);

            if (! $result['valid']) {
                $issues[] = [
                    'event_id' => $event->id,
                    'sequence_id' => $event->sequence_id,
                    'payload_valid' => $result['payload_valid'],
                    'record_valid' => $result['record_valid'],
                    'link_valid' => $result['link_valid'],
                    'expected_previous_hash' => $result['expected_previous_hash'],
                    'actual_previous_hash' => $result['actual_previous_hash'],
                ];
            }
        }

        return [
            'checked_events' => $checked,
            'broken_events' => count($issues),
            'valid' => $issues === [],
            'issues' => $issues,
            'limit' => 1000,
            'truncated' => $events->count() === 1000,
        ];
    }

    private function baseQuery(ImmutableAuditEventFilters $filters): Builder
    {
        $query = ImmutableAuditEvent::query()->forOrganization($filters->organizationId);

        if ($filters->domain !== null) {
            $query->where('domain', $filters->domain);
        }

        if ($filters->eventType !== null) {
            $query->where('event_type', $filters->eventType);
        }

        if ($filters->action !== null) {
            $query->where('action', $filters->action);
        }

        if ($filters->result !== null) {
            $query->where('result', $filters->result);
        }

        if ($filters->severity !== null) {
            $query->where('severity', $filters->severity);
        }

        if ($filters->integrityStatus !== null) {
            $query->where('integrity_status', $filters->integrityStatus);
        }

        if ($filters->actorUserId !== null) {
            $query->where('actor_user_id', $filters->actorUserId);
        }

        if ($filters->projectId !== null) {
            $query->where('project_id', $filters->projectId);
        }

        if ($filters->subjectType !== null) {
            $query->where('subject_type', $filters->subjectType);
        }

        if ($filters->subjectId !== null) {
            $query->where('subject_id', $filters->subjectId);
        }

        if ($filters->correlationId !== null) {
            $query->where('correlation_id', $filters->correlationId);
        }

        if ($filters->source !== null) {
            $query->where('source', $filters->source);
        }

        if ($filters->chainScope !== null) {
            $query->where('chain_scope', $filters->chainScope);
        }

        if ($filters->occurredFrom !== null) {
            $query->where('occurred_at', '>=', $filters->occurredFrom);
        }

        if ($filters->occurredTo !== null) {
            $query->where('occurred_at', '<=', $filters->occurredTo);
        }

        return $query;
    }

    private function countBy(Builder $query, string $column): array
    {
        return $query
            ->selectRaw($column.', count(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $value): int => (int) $value)
            ->all();
    }
}
