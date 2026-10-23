<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Services;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEventSeries;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEventSeriesSegment;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ProjectEventRecurrenceService
{
    public const PLAN_FIELDS = [
        'event_type', 'title', 'description', 'location', 'event_date', 'event_time',
        'end_date', 'end_time', 'duration_minutes', 'is_all_day', 'is_blocking',
        'priority', 'schedule_id', 'related_task_id', 'participants',
        'responsible_users', 'organizations', 'reminder_before_hours', 'color', 'icon',
    ];

    private const INTERVAL_FIELDS = ['event_date', 'event_time', 'end_date', 'end_time', 'duration_minutes', 'is_all_day'];
    private const RULE_FIELDS = ['is_recurring', 'recurrence_pattern', 'recurrence_config', 'recurrence_until'];
    private const CONTROL_FIELDS = ['recurrence_scope', 'operation_id', 'expected_revision'];
    private const MAX_OCCURRENCES = 366;
    private const MAX_OPERATION_BYTES = 2097152;

    public function __construct(private readonly ImmutableAuditRecorder $audit,
        private readonly ProjectEventReferenceValidator $references = new ProjectEventReferenceValidator)
    {
    }

    public function create(ProjectEvent $root, array $data, ?int $actorId, Closure $validate): void
    {
        $until = $this->requireEnd($data['recurrence_until'] ?? null);
        $this->validateRule($root->recurrence_pattern, $data['recurrence_config'] ?? null);
        $this->references->validate(self::PLAN_FIELDS, $root);
        $series = $this->initialize($root, false, $until);
        $changes = [['before' => null, 'after' => $this->snapshot($root)]];
        foreach ($this->plans($series, 0) as $position => $plan) {
            if ($position === 0) {
                continue;
            }
            $validate($plan);
            $child = $this->newOccurrence($root, $series, $position, $plan, $actorId);
            $changes[] = ['before' => null, 'after' => $this->snapshot($child)];
        }
        $this->record($series, $root, $actorId, (string) Str::uuid(), 'created', [], $changes);
        $root->unsetRelation('recurrenceSeries');
    }

    public function update(ProjectEvent $selected, array $data, ?int $actorId, Closure $validate): ProjectEvent
    {
        Validator::make($data, [
            'recurrence_scope' => 'sometimes|required|in:single,future',
            'operation_id' => 'sometimes|required|uuid', 'expected_revision' => 'sometimes|required|integer|min:0',
        ])->validate();

        $operationId = $data['operation_id'] ?? (string) Str::uuid();

        return DB::transaction(function () use ($selected, $data, $actorId, $validate, $operationId): ProjectEvent {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                'project_event_operation:'.$selected->organization_id.':'.$operationId,
            ]);
            $root = $this->lockRoot($selected);
            if ($root->series_id === null && !$root->is_recurring && $root->recurring_parent_id === null
                && ($data['recurrence_scope'] ?? null) !== 'future') {
                throw ValidationException::withMessages(['recurrence_scope' => trans_message('schedule_management.event_future_scope_required')]);
            }
            $series = $this->initialize($root, $root->is_recurring || $selected->recurring_parent_id !== null);
            $series = ProjectEventSeries::query()->whereKey($series->id)
                ->where('organization_id', $selected->organization_id)->where('project_id', $selected->project_id)
                ->lockForUpdate()->firstOrFail();
            $event = ProjectEvent::query()->whereKey($selected->id)->where('series_id', $series->id)
                ->forOrganization($selected->organization_id)->forProject($selected->project_id)->firstOrFail();
            $scope = $data['recurrence_scope'] ?? 'single';
            $hash = hash('sha256', json_encode($this->canonicalize([$event->id, $actorId, $data]), JSON_THROW_ON_ERROR));
            $existing = DB::table('project_event_series_operations')
                ->where('organization_id', $event->organization_id)->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash || (int) $existing->event_id !== $event->id
                    || (int) $existing->actor_id !== (int) $actorId) {
                    throw new ConflictHttpException(trans_message('schedule_management.event_operation_conflict'));
                }

                return $this->replay(json_decode($existing->result, true, 512, JSON_THROW_ON_ERROR));
            }
            if (isset($data['recurrence_scope']) && (!isset($data['operation_id']) || !isset($data['expected_revision']))) {
                throw ValidationException::withMessages(['recurrence_scope' => trans_message('schedule_management.event_scope_context_required')]);
            }
            if (isset($data['expected_revision']) && (int) $data['expected_revision'] !== $series->revision) {
                throw new ConflictHttpException(trans_message('schedule_management.event_series_stale'));
            }
            $memberQuery = ProjectEvent::withTrashed()->where('series_id', $series->id)
                ->forOrganization($selected->organization_id)->forProject($selected->project_id);
            if (($data['recurrence_scope'] ?? 'single') === 'single') {
                $memberQuery->whereKey($selected->id);
            } else {
                $memberQuery->where('series_position', '>=', $event->series_position);
            }
            $this->guardSize($memberQuery);
            $members = $memberQuery->orderBy('id')->lockForUpdate()->get();
            $event = $members->firstWhere('id', $selected->id);
            abort_if($event === null || $event->trashed(), 404);
            $definition = $series->definitionAt((int) $event->series_position);
            $before = $this->snapshot($event);
            $summary = ['scope' => $scope, 'updated' => 0, 'created' => 0, 'preserved' => 0, 'suppressed' => 0];
            $changes = [];
            if ($scope === 'single') {
                $this->assertSingleRuleUnchanged($event, $definition, $data);
                $patch = Arr::except($data, [...self::RULE_FIELDS, ...self::CONTROL_FIELDS]);
                $validate($patch, $event);
                $event->fill($patch);
                $dirty = array_keys($event->getDirty());
                $this->references->validate($dirty, $event);
                $overrides = array_unique([...(array) $event->recurrence_overrides, ...$dirty]);
                if (array_intersect($dirty, self::INTERVAL_FIELDS)) {
                    $overrides = array_unique([...$overrides, ...self::INTERVAL_FIELDS]);
                }
                $event->recurrence_overrides = array_values($overrides);
                if ($dirty !== []) {
                    $event->recurrence_suppressed = false;
                }
                $event->save();
                $changes[] = ['before' => $before, 'after' => $this->snapshot($event)];
                $summary['updated'] = $dirty === [] ? 0 : 1;
            } else {
                [$summary, $changes] = $this->updateFuture($event, $root, $series, $definition, $members, $data, $actorId, $validate);
            }

            $series->increment('revision');
            $this->record($series, $event, $actorId, $operationId, $scope, $before, $changes, $definition);
            $event = $event->fresh(['recurrenceSeries.segments', 'recurringParent']);
            $summary['revision'] = $series->revision;
            $result = ['attributes' => $event->getAttributes(), 'context' => $event->recurrence_context, 'summary' => $summary];
            DB::table('project_event_series_operations')->insert([
                'organization_id' => $event->organization_id, 'series_id' => $series->id,
                'event_id' => $event->id, 'actor_id' => $actorId, 'operation_id' => $operationId,
                'request_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $this->replay($result);
        }, 3);
    }

    public function delete(ProjectEvent $selected, bool $deleteRecurring, ?int $actorId): bool
    {
        if ($deleteRecurring) {
            throw ValidationException::withMessages(['delete_recurring' => trans_message('schedule_management.event_series_delete_protected')]);
        }

        return DB::transaction(function () use ($selected, $actorId): bool {
            $root = $this->lockRoot($selected);
            $series = $this->initialize($root, true);
            $series = ProjectEventSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();
            $event = ProjectEvent::query()->whereKey($selected->id)
                ->forOrganization($selected->organization_id)->forProject($selected->project_id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($event);
            $deleted = (bool) $event->delete();
            $series->increment('revision');
            $this->record($series, $event, $actorId, (string) Str::uuid(), 'deleted', $before, [
                ['before' => $before, 'after' => $this->snapshot($event)],
            ]);

            return $deleted;
        }, 3);
    }

    private function lockRoot(ProjectEvent $event): ProjectEvent
    {
        $rootId = $event->recurring_parent_id ?: $event->id;
        if ($event->series_id !== null) {
            $rootId = ProjectEventSeries::query()->whereKey($event->series_id)
                ->where('organization_id', $event->organization_id)->where('project_id', $event->project_id)
                ->firstOrFail()->root_event_id;
        }

        return ProjectEvent::withTrashed()->whereKey($rootId)->forOrganization($event->organization_id)
            ->forProject($event->project_id)->lockForUpdate()->firstOrFail();
    }

    private function initialize(ProjectEvent $root, bool $legacy, ?string $until = null): ProjectEventSeries
    {
        if ($root->series_id !== null) {
            return ProjectEventSeries::query()->whereKey($root->series_id)
                ->where('organization_id', $root->organization_id)->where('project_id', $root->project_id)->firstOrFail();
        }

        $this->guardSize(ProjectEvent::withTrashed()->forOrganization($root->organization_id)->forProject($root->project_id)
            ->where(fn (Builder $query) => $query->whereKey($root->id)->orWhere('recurring_parent_id', $root->id)));

        $series = ProjectEventSeries::query()->create([
            'organization_id' => $root->organization_id, 'project_id' => $root->project_id,
            'root_event_id' => $root->id, 'revision' => 0,
        ]);
        ProjectEventSeriesSegment::query()->create([
            'series_id' => $series->id, 'revision' => 0, 'from_position' => 0,
            'anchor_date' => $root->event_date->toDateString(), 'pattern' => $root->recurrence_pattern,
            'until' => $until, 'enabled' => true, 'template' => $this->planSnapshot($root),
            'configuration' => $root->recurrence_config,
        ]);
        $members = collect([$root])->concat(ProjectEvent::withTrashed()->where('recurring_parent_id', $root->id)
            ->forOrganization($root->organization_id)->forProject($root->project_id)
            ->orderBy('event_date')->orderBy('id')->lockForUpdate()->get());
        foreach ($members as $position => $member) {
            $timestamps = $member->timestamps;
            $member->timestamps = false;
            $member->forceFill([
                'series_id' => $series->id, 'series_position' => $position,
                'recurrence_overrides' => $legacy ? [...self::PLAN_FIELDS, 'legacy'] : [],
            ])->save();
            $member->timestamps = $timestamps;
        }

        return $series->load('segments');
    }

    private function updateFuture(ProjectEvent $event, ProjectEvent $root, ProjectEventSeries $series,
        array $previous, Collection $members, array $data, ?int $actorId, Closure $validate): array
    {
        foreach (['status', 'notes'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== $event->getAttribute($field)) {
                throw ValidationException::withMessages([$field => trans_message('schedule_management.event_single_field_required')]);
            }
        }
        $enabled = $data['is_recurring'] ?? $previous['enabled'];
        $pattern = array_key_exists('recurrence_pattern', $data) ? $data['recurrence_pattern'] : $previous['pattern'];
        if ($enabled) {
            $this->validateRule($pattern, $data['recurrence_config'] ?? $previous['configuration']);
            $this->requireEnd(array_key_exists('recurrence_until', $data) ? $data['recurrence_until'] : $previous['until']);
        }
        $position = (int) $event->series_position;
        $validate($data, $event);
        $anchor = Carbon::parse($data['event_date'] ?? $event->event_date->toDateString(), 'UTC');
        $interval = [];
        if (!isset($data['recurrence_pattern']) && (isset($data['event_date']) || in_array('legacy', (array) $event->recurrence_overrides, true))) {
            $interval['shift_days'] = (int) Carbon::parse($previous['plan']['event_date'], 'UTC')->diffInDays($anchor, false);
        }
        if (array_key_exists('end_date', $data)) {
            $interval['end_span_days'] = $data['end_date'] === null ? null
                : (int) $anchor->diffInDays(Carbon::parse($data['end_date'], 'UTC'), false);
        }
        ProjectEventSeriesSegment::query()->create([
            'series_id' => $series->id, 'revision' => $series->revision + 1, 'from_position' => $position,
            'anchor_date' => $anchor->toDateString(), 'pattern' => $data['recurrence_pattern'] ?? null,
            'until' => $data['recurrence_until'] ?? null, 'enabled' => $data['is_recurring'] ?? null,
            'template' => Arr::except(Arr::only($data, self::PLAN_FIELDS), ['event_date', 'end_date']),
            'interval' => $interval, 'configuration' => $data['recurrence_config'] ?? null,
        ]);
        $series->load('segments');
        $plans = $this->plans($series, $position);
        $summary = ['scope' => 'future', 'updated' => 0, 'created' => 0, 'preserved' => 0, 'suppressed' => 0];
        $changes = [];
        $byPosition = $members->keyBy('series_position');
        foreach ($members->where('series_position', '>=', $position) as $member) {
            $overrides = (array) $member->recurrence_overrides;
            if ($member->trashed() || in_array($member->status, ['completed', 'cancelled', 'in_progress'], true)
                || ($member->id !== $event->id && in_array('legacy', $overrides, true))) {
                $summary['preserved']++;
                continue;
            }
            $before = $this->snapshot($member);
            $plan = $plans[$member->series_position] ?? null;
            $included = $plan !== null;
            if ($plan === null && $member->id === $event->id && Arr::only($data, self::PLAN_FIELDS) !== []) {
                $plan = $series->definitionAt((int) $member->series_position)['plan'];
            }
            if ($plan === null) {
                if ($overrides !== []) {
                    $summary['preserved']++;
                    continue;
                }
                if (!$member->recurrence_suppressed) {
                    $member->recurrence_suppressed = true;
                    $summary['suppressed']++;
                }
            } else {
                if ($member->id === $event->id) {
                    $explicit = array_keys(Arr::only($data, self::PLAN_FIELDS));
                    $overrides = array_values(array_diff($overrides, $explicit));
                    $member->recurrence_overrides = $overrides;
                }
                $member->fill(Arr::except($plan, $overrides));
                if ($included) {
                    $member->recurrence_suppressed = false;
                }
                $validate([], $member);
            }
            if ($member->isDirty()) {
                $this->references->validate(array_keys($member->getDirty()), $member);
                $member->save();
                $summary['updated']++;
                $changes[] = ['before' => $before, 'after' => $this->snapshot($member)];
            }
        }
        foreach ($plans as $slot => $plan) {
            if ($byPosition->has($slot)) {
                continue;
            }
            $validate($plan);
            $member = $this->newOccurrence($root, $series, $slot, $plan, $actorId);
            $summary['created']++;
            $changes[] = ['before' => null, 'after' => $this->snapshot($member)];
        }

        return [$summary, $changes];
    }

    private function plans(ProjectEventSeries $series, int $pivot): array
    {
        $plans = [];
        $bytes = 0;
        $lastBoundary = max($pivot, (int) $series->segments->max('from_position'));
        if ($lastBoundary - $pivot > 10000) {
            throw ValidationException::withMessages(['recurrence_until' => trans_message('schedule_management.event_recurrence_limit')]);
        }
        for ($position = $pivot; $position <= $lastBoundary + self::MAX_OCCURRENCES; $position++) {
            $definition = $series->definitionAt($position);
            $pastEnd = $definition['until'] === null || $definition['plan']['event_date'] > $definition['until'];
            $stopped = !$definition['enabled'] && $position > $definition['enabled_from_position'];
            if ($pastEnd || $stopped) {
                if ($position >= $lastBoundary) {
                    return $plans;
                }
                continue;
            }
            if (count($plans) >= self::MAX_OCCURRENCES) {
                throw ValidationException::withMessages(['recurrence_until' => trans_message('schedule_management.event_recurrence_limit')]);
            }
            $bytes += strlen(json_encode($definition['plan'], JSON_THROW_ON_ERROR));
            if ($bytes > self::MAX_OPERATION_BYTES) {
                throw ValidationException::withMessages(['recurrence_until' => trans_message('schedule_management.event_recurrence_size_limit')]);
            }
            $plans[$position] = $definition['plan'];
        }

        throw ValidationException::withMessages(['recurrence_until' => trans_message('schedule_management.event_recurrence_limit')]);
    }

    private function newOccurrence(ProjectEvent $root, ProjectEventSeries $series, int $position, array $plan, ?int $actorId): ProjectEvent
    {
        $event = new ProjectEvent([
            ...$plan, 'organization_id' => $series->organization_id, 'project_id' => $series->project_id,
            'created_by_user_id' => $actorId, 'recurring_parent_id' => $root->id,
            'series_id' => $series->id, 'series_position' => $position, 'recurrence_overrides' => [],
            'is_recurring' => false, 'status' => 'scheduled', 'reminder_sent' => false,
        ]);
        $this->references->validate(self::PLAN_FIELDS, $event);
        $event->save();

        return $event;
    }

    private function assertSingleRuleUnchanged(ProjectEvent $event, array $definition, array $data): void
    {
        $current = [
            'is_recurring' => $definition['enabled'], 'recurrence_pattern' => $definition['pattern'],
            'recurrence_until' => $definition['until'], 'recurrence_config' => $definition['configuration'],
        ];
        foreach (self::RULE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if (!isset($data['recurrence_scope']) && $data[$field] == $event->getAttribute($field)) {
                continue;
            }
            if (($data[$field] ?? null) != $current[$field]) {
                throw ValidationException::withMessages([$field => trans_message('schedule_management.event_future_scope_required')]);
            }
        }
    }

    private function validateRule(?string $pattern, ?array $config): void
    {
        if (!in_array($pattern, ['daily', 'weekly', 'monthly'], true)) {
            throw ValidationException::withMessages(['recurrence_pattern' => trans_message('schedule_management.event_recurrence_pattern_required')]);
        }
        if ($config !== null && $config !== []) {
            throw ValidationException::withMessages(['recurrence_config' => trans_message('schedule_management.event_recurrence_config_unsupported')]);
        }
    }

    private function requireEnd(?string $until): string
    {
        if ($until === null || $until === '') {
            throw ValidationException::withMessages(['recurrence_until' => trans_message('schedule_management.event_recurrence_end_required')]);
        }

        return Carbon::parse($until)->toDateString();
    }

    private function planSnapshot(ProjectEvent $event): array
    {
        $result = [];
        foreach (self::PLAN_FIELDS as $field) {
            $value = $event->getAttribute($field);
            $result[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $result;
    }

    private function snapshot(ProjectEvent $event): array
    {
        return [
            'id' => $event->id, ...$this->planSnapshot($event), 'status' => $event->status,
            'notes' => $event->notes, 'series_id' => $event->series_id,
            'position' => $event->series_position, 'suppressed' => (bool) $event->recurrence_suppressed,
            'overrides' => (array) $event->recurrence_overrides, 'deleted_at' => $event->deleted_at?->toIso8601String(),
        ];
    }

    private function record(ProjectEventSeries $series, ProjectEvent $event, ?int $actorId, string $operationId,
        string $scope, array $before, array $changes, array $beforeDefinition = []): void
    {
        if (strlen(json_encode([$before, $changes], JSON_THROW_ON_ERROR)) > self::MAX_OPERATION_BYTES * 2) {
            throw ValidationException::withMessages(['recurrence_scope' => trans_message('schedule_management.event_recurrence_size_limit')]);
        }
        $this->audit->record(new ImmutableAuditEventData(
            organizationId: (int) $series->organization_id, domain: 'schedule_management',
            eventType: 'project_event.recurrence_'.$scope, action: $scope, source: 'project_calendar',
            projectId: (int) $series->project_id, actorType: $actorId === null ? 'system' : 'user', actorUserId: $actorId,
            sourceModel: ProjectEvent::class, sourceTable: 'project_events', sourceEventId: 'recurrence:'.$operationId,
            idempotencyKey: $operationId, subjectType: 'project_event', subjectId: $event->id, subjectLabel: $event->title,
            beforeState: ['event' => $before, 'definition' => $beforeDefinition],
            afterState: ['revision' => $series->revision, 'changes' => $changes, 'definition' => $series->definitionAt((int) $event->series_position)],
            domainContext: ['series_id' => $series->id, 'pivot_id' => $event->id, 'scope' => $scope],
            chainScope: 'organization:'.$series->organization_id.':project_event_series:'.$series->id,
        ));
    }

    private function guardSize(Builder $query): void
    {
        $size = (clone $query)->reorder()->toBase()->selectRaw(
            'COUNT(*) AS total, COALESCE(SUM(octet_length(row_to_json(project_events)::text)), 0) AS bytes'
        )->first();
        if ((int) $size->total > 1000 || (int) $size->bytes > self::MAX_OPERATION_BYTES) {
            throw ValidationException::withMessages(['recurrence_scope' => trans_message('schedule_management.event_recurrence_size_limit')]);
        }
    }

    private function canonicalize(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }

        return $value;
    }

    private function replay(array $result): ProjectEvent
    {
        $event = (new ProjectEvent)->newFromBuilder($result['attributes']);
        $event->recurrenceResponseContext = $result['context'];
        $event->recurrenceOperationSummary = $result['summary'];

        return $event;
    }
}
