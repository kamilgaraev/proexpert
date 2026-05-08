<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\DTOs\Activity\ActivityEventFilters;
use App\Models\Activity\ActivityEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ActivityEventQueryService
{
    public function paginate(int $organizationId, ActivityEventFilters $filters): LengthAwarePaginator
    {
        return $this->baseQuery($organizationId, $filters)
            ->with(['actor:id,name,email', 'targetUser:id,name,email', 'project:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($filters->perPage, ['*'], 'page', $filters->page);
    }

    public function findForOrganization(int $organizationId, int $eventId): ?ActivityEvent
    {
        return ActivityEvent::query()
            ->forOrganization($organizationId)
            ->with(['actor:id,name,email', 'targetUser:id,name,email', 'project:id,name'])
            ->find($eventId);
    }

    public function summary(int $organizationId, ActivityEventFilters $filters): array
    {
        $query = $this->baseQuery($organizationId, $filters);

        return [
            'total' => (clone $query)->count(),
            'failed' => (clone $query)->whereIn('result', ['failed', 'blocked'])->count(),
            'warnings' => (clone $query)->whereIn('severity', ['warning', 'critical'])->count(),
            'by_module' => $this->countBy(clone $query, 'module'),
            'by_result' => $this->countBy(clone $query, 'result'),
            'top_actors' => (clone $query)
                ->selectRaw('actor_user_id, actor_name, COUNT(*) as total')
                ->whereNotNull('actor_user_id')
                ->groupBy('actor_user_id', 'actor_name')
                ->orderByDesc('total')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'id' => $row->actor_user_id,
                    'name' => $row->actor_name,
                    'total' => (int) $row->total,
                ])
                ->values(),
        ];
    }

    public function actors(int $organizationId, ActivityEventFilters $filters): Collection
    {
        return $this->baseQuery($organizationId, $filters)
            ->select('actor_user_id', 'actor_name', 'actor_email')
            ->whereNotNull('actor_user_id')
            ->groupBy('actor_user_id', 'actor_name', 'actor_email')
            ->orderBy('actor_name')
            ->limit(200)
            ->get()
            ->map(fn (ActivityEvent $event) => [
                'id' => $event->actor_user_id,
                'name' => $event->actor_name,
                'email' => $event->actor_email,
            ])
            ->values();
    }

    public function exportRows(int $organizationId, ActivityEventFilters $filters, int $limit = 10000): Collection
    {
        return $this->baseQuery($organizationId, $filters)
            ->with(['project:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function baseQuery(int $organizationId, ActivityEventFilters $filters): Builder
    {
        $query = ActivityEvent::query()->forOrganization($organizationId);

        $this->applyEquals($query, 'actor_user_id', $filters->actorUserId);
        $this->applyEquals($query, 'target_user_id', $filters->targetUserId);
        $this->applyEquals($query, 'project_id', $filters->projectId);
        $this->applyEquals($query, 'module', $filters->module);
        $this->applyEquals($query, 'event_type', $filters->eventType);
        $this->applyEquals($query, 'action', $filters->action);
        $this->applyEquals($query, 'result', $filters->result);
        $this->applyEquals($query, 'severity', $filters->severity);
        $this->applyEquals($query, 'subject_type', $filters->subjectType);
        $this->applyEquals($query, 'subject_id', $filters->subjectId);

        if ($filters->dateFrom !== null) {
            $query->where('occurred_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->where('occurred_at', '<=', $filters->dateTo);
        }

        if ($filters->search !== null) {
            $search = '%' . mb_strtolower($filters->search) . '%';
            $query->where(function (Builder $nested) use ($search): void {
                $nested
                    ->whereRaw('LOWER(title) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(actor_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(subject_label) LIKE ?', [$search]);
            });
        }

        return $query;
    }

    private function applyEquals(Builder $query, string $column, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            $query->where($column, $value);
        }
    }

    private function countBy(Builder $query, string $column): Collection
    {
        return $query
            ->selectRaw("{$column}, COUNT(*) as total")
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'key' => $row->{$column},
                'total' => (int) $row->total,
            ])
            ->values();
    }
}
