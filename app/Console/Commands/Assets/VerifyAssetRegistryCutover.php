<?php

declare(strict_types=1);

namespace App\Console\Commands\Assets;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class VerifyAssetRegistryCutover extends Command
{
    /** @var list<string> */
    private const OPERATION_TABLES = [
        'machinery_assignments',
        'machinery_shift_reports',
        'machinery_downtimes',
        'machinery_fuel_issues',
        'machinery_production_records',
        'machinery_maintenance_orders',
        'machinery_defects',
        'maintenance_inspections',
    ];

    protected $signature = 'assets:verify-cutover
        {--format=table : table или json}
        {--details : Добавить агрегированную диагностику}
        {--since= : Начало окна наблюдения в ISO-8601; по умолчанию используется observation_hours}';

    protected $description = 'Проверяет go/no-go условия перехода на единый реестр имущества';

    public function handle(): int
    {
        $observedAt = CarbonImmutable::instance(now())->utc();
        $observationStartedAt = $this->observationStartedAt($observedAt);
        if ($observationStartedAt === false) {
            return self::FAILURE;
        }

        $gates = [
            'missing_links' => $this->missingLinks(),
            'duplicate_canonical_assets' => $this->duplicateCanonicalAssets(),
            'dual_write_divergence' => $this->dualWriteDivergence(),
            'operations_without_organization_asset_id' => $this->operationsWithoutCanonicalId(),
            'open_assignments_with_inconsistent_placement' => $this->inconsistentOpenAssignments(),
        ];
        $report = [
            ...$gates,
            'ready' => array_sum($gates) === 0,
            'observed_at' => $observedAt->toIso8601String(),
            'release_sha' => $this->releaseSha(),
        ];
        if ((bool) $this->option('details')) {
            $report['details'] = [
                'missing_links' => $this->missingLinksBreakdown(),
                'operations_without_canonical_id' => $this->operationsWithoutCanonicalIdBreakdown(),
                'assignments' => $this->assignmentRiskBreakdown(),
                'scope_repair_evidence' => $this->scopeRepairEvidence(),
                'activity' => $this->activityBreakdown($observationStartedAt, $observedAt),
            ];
        }

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['gate', 'count'], collect($gates)
                ->map(fn (int $count, string $gate): array => [$gate, $count])
                ->values()
                ->all());
            $this->line($report['ready'] ? 'GO' : 'NO-GO');
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }

    private function observationStartedAt(CarbonImmutable $observedAt): CarbonImmutable|false
    {
        $value = $this->option('since');
        if ($value === null || trim((string) $value) === '') {
            return $observedAt->subHours((int) config('asset_registry.observation_hours', 24));
        }

        $value = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            $this->error('Некорректное значение --since: требуется дата и время ISO-8601.');

            return false;
        }

        try {
            $since = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            $this->error('Некорректное значение --since: требуется дата и время ISO-8601.');

            return false;
        }

        if ($since->isAfter($observedAt)) {
            $this->error('Некорректное значение --since: начало окна находится в будущем.');

            return false;
        }

        return $since;
    }

    private function releaseSha(): ?string
    {
        $releaseSha = trim((string) config('asset_registry.release_sha', ''));

        return $releaseSha !== '' ? $releaseSha : null;
    }

    /**
     * @return array{since: string, until: string, intake_events: int, assignment_events: int, assignments: int, shift_reports: int, approved_shift_reports: int, completed_operational_cycles: int}
     */
    private function activityBreakdown(CarbonImmutable $since, CarbonImmutable $until): array
    {
        $custodyEvents = DB::table('asset_custody_events')
            ->whereBetween('occurred_at', [$since, $until]);
        $assignments = DB::table('machinery_assignments')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$since, $until]);
        $shifts = DB::table('machinery_shift_reports')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$since, $until]);

        $completedCycles = DB::table('machinery_shift_reports as shift')
            ->join('machinery_assignments as assignment', static function (JoinClause $join): void {
                $join->on('assignment.id', '=', 'shift.assignment_id')
                    ->on('assignment.organization_asset_id', '=', 'shift.organization_asset_id');
            })
            ->whereNotNull('shift.organization_asset_id')
            ->whereNull('shift.deleted_at')
            ->whereNull('assignment.deleted_at')
            ->whereBetween('assignment.created_at', [$since, $until])
            ->whereBetween('shift.created_at', [$since, $until])
            ->whereBetween('shift.approved_at', [$since, $until])
            ->whereColumn('assignment.created_at', '<=', 'shift.created_at')
            ->whereColumn('shift.created_at', '<=', 'shift.approved_at')
            ->whereExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('asset_custody_events as intake')
                ->whereColumn('intake.organization_asset_id', 'shift.organization_asset_id')
                ->where('intake.event_type', 'created')
                ->where('intake.occurred_at', '>=', $since)
                ->whereColumn('intake.occurred_at', '<=', 'assignment.created_at'))
            ->whereExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('asset_custody_events as dispatch')
                ->whereColumn('dispatch.organization_asset_id', 'shift.organization_asset_id')
                ->whereIn('dispatch.event_type', ['machinery_assigned', 'issued'])
                ->whereColumn('dispatch.occurred_at', '>=', 'assignment.created_at')
                ->whereColumn('dispatch.occurred_at', '<=', 'shift.created_at'))
            ->distinct()
            ->count('shift.organization_asset_id');

        return [
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'intake_events' => (int) (clone $custodyEvents)->where('event_type', 'created')->count(),
            'assignment_events' => (int) (clone $custodyEvents)->whereIn('event_type', ['machinery_assigned', 'issued'])->count(),
            'assignments' => (int) $assignments->count(),
            'shift_reports' => (int) $shifts->count(),
            'approved_shift_reports' => (int) (clone $shifts)->whereBetween('approved_at', [$since, $until])->count(),
            'completed_operational_cycles' => (int) $completedCycles,
        ];
    }

    private function missingLinks(): int
    {
        return array_sum($this->missingLinksBreakdown());
    }

    /** @return array{machinery_assets: int, operation_profiles: int, serialized_projections: int} */
    private function missingLinksBreakdown(): array
    {
        $machineryAssets = (int) DB::table('machinery_assets')->whereNull('organization_asset_id')->count();
        $operationProfiles = (int) DB::table('organization_assets')
            ->whereNull('deleted_at')
            ->whereNotExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('asset_operation_profiles')
                ->whereColumn('asset_operation_profiles.organization_asset_id', 'organization_assets.id'))
            ->count();
        $serializedProjections = (int) DB::table('organization_assets')
            ->where('accounting_mode', 'serialized')
            ->whereNotNull('material_id')
            ->whereNull('deleted_at')
            ->whereNotExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('machinery_assets')
                ->whereColumn('machinery_assets.organization_asset_id', 'organization_assets.id'))
            ->count();

        return [
            'machinery_assets' => $machineryAssets,
            'operation_profiles' => $operationProfiles,
            'serialized_projections' => $serializedProjections,
        ];
    }

    private function duplicateCanonicalAssets(): int
    {
        $duplicateLinkGroups = DB::table('machinery_assets')
            ->select('organization_asset_id')
            ->whereNotNull('organization_asset_id')
            ->groupBy('organization_asset_id')
            ->havingRaw('COUNT(*) > 1');
        $duplicateLinks = DB::query()->fromSub($duplicateLinkGroups, 'duplicate_links')->count();

        $sourceCounts = [];
        foreach (DB::table('organization_assets')->whereNull('deleted_at')->whereNotNull('metadata')->get(['metadata']) as $row) {
            $metadata = is_array($row->metadata) ? $row->metadata : json_decode((string) $row->metadata, true);
            $source = is_array($metadata) ? ($metadata['legacy_source'] ?? null) : null;
            if (! is_array($source) || ! isset($source['table'], $source['id'])) {
                continue;
            }
            $key = $source['table'].':'.$source['id'];
            $sourceCounts[$key] = ($sourceCounts[$key] ?? 0) + 1;
        }

        return $duplicateLinks + count(array_filter($sourceCounts, static fn (int $count): bool => $count > 1));
    }

    private function dualWriteDivergence(): int
    {
        $divergence = 0;
        $rows = DB::table('machinery_assets as legacy')
            ->join('organization_assets as canonical', 'canonical.id', '=', 'legacy.organization_asset_id')
            ->leftJoin('asset_operation_profiles as profile', 'profile.organization_asset_id', '=', 'canonical.id')
            ->whereNull('legacy.deleted_at')
            ->get([
                'legacy.organization_id as legacy_organization_id',
                'canonical.organization_id as canonical_organization_id',
                'legacy.name as legacy_name',
                'legacy.asset_code as legacy_asset_code',
                'canonical.name as canonical_name',
                'legacy.inventory_number as legacy_inventory_number',
                'canonical.inventory_number as canonical_inventory_number',
                'legacy.ownership_type as legacy_ownership_type',
                'canonical.ownership_type as canonical_ownership_type',
                'legacy.machinery_id as legacy_machinery_id',
                'canonical.machinery_id as canonical_machinery_id',
                'legacy.current_project_id as legacy_project_id',
                'canonical.current_project_id as canonical_project_id',
                'legacy.operating_cost_per_hour as legacy_operating_cost_per_hour',
                'profile.operating_cost_per_hour as canonical_operating_cost_per_hour',
                'legacy.fuel_type as legacy_fuel_type',
                'profile.fuel_type as canonical_fuel_type',
                'legacy.fuel_consumption_rate as legacy_fuel_consumption_rate',
                'profile.fuel_consumption_rate as canonical_fuel_consumption_rate',
                'legacy.meter_hours as legacy_meter_value',
                'profile.meter_value as canonical_meter_value',
            ]);

        foreach ($rows as $row) {
            if (
                (int) $row->legacy_organization_id !== (int) $row->canonical_organization_id
                || trim((string) $row->legacy_name) !== trim((string) $row->canonical_name)
                || $this->nullableString($row->legacy_inventory_number ?? $row->legacy_asset_code) !== $this->nullableString($row->canonical_inventory_number)
                || (string) $row->legacy_ownership_type !== (string) $row->canonical_ownership_type
                || $this->nullableInt($row->legacy_machinery_id) !== $this->nullableInt($row->canonical_machinery_id)
                || $this->nullableInt($row->legacy_project_id) !== $this->nullableInt($row->canonical_project_id)
                || ! $this->numericValuesMatch($row->legacy_operating_cost_per_hour, $row->canonical_operating_cost_per_hour)
                || $this->nullableString($row->legacy_fuel_type) !== $this->nullableString($row->canonical_fuel_type)
                || ! $this->numericValuesMatch($row->legacy_fuel_consumption_rate, $row->canonical_fuel_consumption_rate)
                || ! $this->numericValuesMatch($row->legacy_meter_value, $row->canonical_meter_value)
            ) {
                $divergence++;
            }
        }

        return $divergence;
    }

    private function operationsWithoutCanonicalId(): int
    {
        return array_sum($this->operationsWithoutCanonicalIdBreakdown());
    }

    /** @return array<string, int> */
    private function operationsWithoutCanonicalIdBreakdown(): array
    {
        $counts = [];
        foreach (self::OPERATION_TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) && Schema::hasColumn($table, 'organization_asset_id')
                ? (int) DB::table($table)->whereNull('organization_asset_id')->count()
                : 0;
        }

        return $counts;
    }

    /** @return array{active: int, currently_effective: int, overlapping_pairs: int, distinct_projects: int, assignment_organization_mismatches: int, project_organization_mismatches: int, scope_mismatch_assets: int, scope_mismatch_assets_without_candidate: int, scope_mismatch_assets_with_one_candidate: int, scope_mismatch_assets_with_multiple_candidates: int} */
    private function assignmentRiskBreakdown(): array
    {
        if (! Schema::hasTable('machinery_assignments')) {
            return ['active' => 0, 'currently_effective' => 0, 'overlapping_pairs' => 0, 'distinct_projects' => 0, 'assignment_organization_mismatches' => 0, 'project_organization_mismatches' => 0, 'scope_mismatch_assets' => 0, 'scope_mismatch_assets_without_candidate' => 0, 'scope_mismatch_assets_with_one_candidate' => 0, 'scope_mismatch_assets_with_multiple_candidates' => 0];
        }

        $active = DB::table('machinery_assignments')
            ->where('status', 'active')
            ->whereNull('deleted_at');
        $currentlyEffective = (clone $active)
            ->where('planned_start_at', '<=', now())
            ->where(static fn (Builder $query) => $query
                ->whereNull('planned_end_at')
                ->orWhere('planned_end_at', '>', now()))
            ->count();
        $overlappingPairs = DB::table('machinery_assignments as earlier')
            ->join('machinery_assignments as later', static fn ($join) => $join
                ->on('later.asset_id', '=', 'earlier.asset_id')
                ->on('later.id', '>', 'earlier.id'))
            ->where('earlier.status', 'active')
            ->where('later.status', 'active')
            ->whereNull('earlier.deleted_at')
            ->whereNull('later.deleted_at')
            ->where(static fn (Builder $query) => $query
                ->whereNull('later.planned_end_at')
                ->orWhereColumn('earlier.planned_start_at', '<', 'later.planned_end_at'))
            ->where(static fn (Builder $query) => $query
                ->whereNull('earlier.planned_end_at')
                ->orWhereColumn('later.planned_start_at', '<', 'earlier.planned_end_at'))
            ->count();
        $scopeMismatchAssets = DB::table('machinery_assignments as assignment')
            ->join('machinery_assets as asset', 'asset.id', '=', 'assignment.asset_id')
            ->join('projects as project', 'project.id', '=', 'assignment.project_id')
            ->whereColumn('project.organization_id', '<>', 'asset.organization_id')
            ->whereNotExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('project_organization as participant')
                ->whereColumn('participant.project_id', 'assignment.project_id')
                ->whereColumn('participant.organization_id', 'asset.organization_id')
                ->where('participant.is_active', true))
            ->distinct()
            ->get(['asset.id', 'asset.organization_id']);
        $candidateBuckets = ['none' => 0, 'one' => 0, 'many' => 0];
        foreach ($scopeMismatchAssets as $asset) {
            $projects = DB::table('projects')->where(static fn (Builder $query) => $query
                ->where('organization_id', $asset->organization_id)
                ->orWhereExists(static fn (Builder $participant) => $participant
                    ->selectRaw('1')
                    ->from('project_organization')
                    ->whereColumn('project_organization.project_id', 'projects.id')
                    ->where('project_organization.organization_id', $asset->organization_id)
                    ->where('project_organization.is_active', true)));
            if (Schema::hasColumn('projects', 'deleted_at')) {
                $projects->whereNull('deleted_at');
            }
            $count = (int) $projects->count();
            $candidateBuckets[$count === 0 ? 'none' : ($count === 1 ? 'one' : 'many')]++;
        }

        return [
            'active' => (int) (clone $active)->count(),
            'currently_effective' => (int) $currentlyEffective,
            'overlapping_pairs' => (int) $overlappingPairs,
            'distinct_projects' => (int) (clone $active)->distinct()->count('project_id'),
            'assignment_organization_mismatches' => (int) DB::table('machinery_assignments as assignment')
                ->join('machinery_assets as asset', 'asset.id', '=', 'assignment.asset_id')
                ->whereColumn('assignment.organization_id', '<>', 'asset.organization_id')
                ->count(),
            'project_organization_mismatches' => (int) DB::table('machinery_assignments as assignment')
                ->join('machinery_assets as asset', 'asset.id', '=', 'assignment.asset_id')
                ->join('projects as project', 'project.id', '=', 'assignment.project_id')
                ->whereColumn('project.organization_id', '<>', 'asset.organization_id')
                ->whereNotExists(static fn (Builder $query) => $query
                    ->selectRaw('1')
                    ->from('project_organization as participant')
                    ->whereColumn('participant.project_id', 'assignment.project_id')
                    ->whereColumn('participant.organization_id', 'asset.organization_id')
                    ->where('participant.is_active', true))
                ->count(),
            'scope_mismatch_assets' => $scopeMismatchAssets->count(),
            'scope_mismatch_assets_without_candidate' => $candidateBuckets['none'],
            'scope_mismatch_assets_with_one_candidate' => $candidateBuckets['one'],
            'scope_mismatch_assets_with_multiple_candidates' => $candidateBuckets['many'],
        ];
    }

    private function inconsistentOpenAssignments(): int
    {
        if (! Schema::hasTable('machinery_assignments')) {
            return 0;
        }

        return (int) DB::table('machinery_assignments as assignment')
            ->join('machinery_assets as legacy', 'legacy.id', '=', 'assignment.asset_id')
            ->leftJoin('organization_assets as canonical', 'canonical.id', '=', 'assignment.organization_asset_id')
            ->where('assignment.status', 'active')
            ->whereNull('assignment.deleted_at')
            ->where(static fn (Builder $query) => $query
                ->whereNull('canonical.id')
                ->orWhereColumn('assignment.organization_id', '<>', 'canonical.organization_id')
                ->orWhereColumn('assignment.organization_asset_id', '<>', 'legacy.organization_asset_id')
                ->orWhereNull('canonical.current_project_id')
                ->orWhereColumn('assignment.project_id', '<>', 'canonical.current_project_id'))
            ->count();
    }

    /** @return list<array<string, int|bool|null|list<int>>> */
    private function scopeRepairEvidence(): array
    {
        if (! Schema::hasTable('machinery_assignments')) {
            return [];
        }

        $assets = DB::table('machinery_assignments as assignment')
            ->join('machinery_assets as asset', 'asset.id', '=', 'assignment.asset_id')
            ->join('projects as project', 'project.id', '=', 'assignment.project_id')
            ->whereColumn('project.organization_id', '<>', 'asset.organization_id')
            ->whereNotExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('project_organization as participant')
                ->whereColumn('participant.project_id', 'assignment.project_id')
                ->whereColumn('participant.organization_id', 'asset.organization_id')
                ->where('participant.is_active', true))
            ->distinct()
            ->orderBy('asset.id')
            ->get(['asset.id', 'asset.organization_id', 'asset.current_project_id', 'asset.current_schedule_task_id']);

        return $assets->map(function (object $asset): array {
            $assetId = (int) $asset->id;
            $organizationId = (int) $asset->organization_id;
            $localCandidates = DB::table('projects')->where(static fn (Builder $query) => $query
                ->where('organization_id', $organizationId)
                ->orWhereExists(static fn (Builder $participant) => $participant
                    ->selectRaw('1')
                    ->from('project_organization')
                    ->whereColumn('project_organization.project_id', 'projects.id')
                    ->where('project_organization.organization_id', $organizationId)
                    ->where('project_organization.is_active', true)));
            if (Schema::hasColumn('projects', 'deleted_at')) {
                $localCandidates->whereNull('deleted_at');
            }

            $foreignAssignmentProjectIds = DB::table('machinery_assignments as assignment')
                ->join('projects as project', 'project.id', '=', 'assignment.project_id')
                ->where('assignment.asset_id', $assetId)
                ->where('project.organization_id', '<>', $organizationId)
                ->distinct()
                ->orderBy('assignment.project_id')
                ->pluck('assignment.project_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $operationProjectIds = collect();
            foreach (self::OPERATION_TABLES as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'asset_id') || ! Schema::hasColumn($table, 'project_id')) {
                    continue;
                }
                $operationProjectIds->push(...DB::table($table)
                    ->where('asset_id', $assetId)
                    ->whereNotNull('project_id')
                    ->pluck('project_id')
                    ->all());
            }
            $operationProjectIds = $operationProjectIds
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();

            $scheduleProjectIds = collect();
            if (Schema::hasTable('schedule_tasks') && Schema::hasTable('project_schedules')) {
                $scheduleTaskIds = DB::table('machinery_assignments')
                    ->where('asset_id', $assetId)
                    ->whereNotNull('schedule_task_id')
                    ->pluck('schedule_task_id');
                if ($asset->current_schedule_task_id !== null) {
                    $scheduleTaskIds->push((int) $asset->current_schedule_task_id);
                }
                if ($scheduleTaskIds->isNotEmpty()) {
                    $scheduleProjectIds = DB::table('schedule_tasks as task')
                        ->join('project_schedules as schedule', 'schedule.id', '=', 'task.schedule_id')
                        ->whereIn('task.id', $scheduleTaskIds->unique()->all())
                        ->distinct()
                        ->orderBy('schedule.project_id')
                        ->pluck('schedule.project_id')
                        ->map(static fn ($id): int => (int) $id);
                }
            }

            $legacyCurrentProjectId = $this->nullableInt($asset->current_project_id);

            return [
                'asset_id' => $assetId,
                'organization_id' => $organizationId,
                'foreign_assignment_project_ids' => $foreignAssignmentProjectIds,
                'accessible_foreign_assignment_project_ids' => Schema::hasTable('project_organization')
                    ? DB::table('project_organization')
                        ->where('organization_id', $organizationId)
                        ->where('is_active', true)
                        ->whereIn('project_id', $foreignAssignmentProjectIds)
                        ->distinct()
                        ->orderBy('project_id')
                        ->pluck('project_id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all()
                    : [],
                'legacy_current_project_id' => $legacyCurrentProjectId,
                'legacy_current_project_is_local' => $legacyCurrentProjectId !== null && DB::table('projects')
                    ->where('id', $legacyCurrentProjectId)
                    ->where('organization_id', $organizationId)
                    ->exists(),
                'schedule_project_ids' => $scheduleProjectIds->unique()->sort()->values()->all(),
                'operation_project_ids' => $operationProjectIds->all(),
                'local_operation_project_ids' => $operationProjectIds
                    ->filter(static fn (int $projectId): bool => DB::table('projects')
                        ->where('id', $projectId)
                        ->where('organization_id', $organizationId)
                        ->exists())
                    ->values()
                    ->all(),
                'local_candidate_project_ids' => $localCandidates->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            ];
        })->all();
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : trim((string) $value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function numericValuesMatch(mixed $first, mixed $second): bool
    {
        if ($first === null || $second === null) {
            return $first === null && $second === null;
        }

        return abs((float) $first - (float) $second) < 0.0005;
    }
}
