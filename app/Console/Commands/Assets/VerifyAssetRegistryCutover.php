<?php

declare(strict_types=1);

namespace App\Console\Commands\Assets;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    protected $signature = 'assets:verify-cutover {--format=table : table или json} {--details : Добавить агрегированную диагностику}';

    protected $description = 'Проверяет go/no-go условия перехода на единый реестр имущества';

    public function handle(): int
    {
        $report = [
            'missing_links' => $this->missingLinks(),
            'duplicate_canonical_assets' => $this->duplicateCanonicalAssets(),
            'dual_write_divergence' => $this->dualWriteDivergence(),
            'operations_without_organization_asset_id' => $this->operationsWithoutCanonicalId(),
            'open_assignments_with_inconsistent_placement' => $this->inconsistentOpenAssignments(),
        ];
        $report['ready'] = array_sum($report) === 0;
        if ((bool) $this->option('details')) {
            $report['details'] = [
                'missing_links' => $this->missingLinksBreakdown(),
                'operations_without_canonical_id' => $this->operationsWithoutCanonicalIdBreakdown(),
                'assignments' => $this->assignmentRiskBreakdown(),
                'scope_repair_evidence' => $this->scopeRepairEvidence(),
            ];
        }

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['gate', 'count'], collect($report)
                ->except('ready')
                ->map(fn (int $count, string $gate): array => [$gate, $count])
                ->values()
                ->all());
            $this->line($report['ready'] ? 'GO' : 'NO-GO');
        }

        return $report['ready'] ? self::SUCCESS : self::FAILURE;
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
            ]);

        foreach ($rows as $row) {
            if (
                (int) $row->legacy_organization_id !== (int) $row->canonical_organization_id
                || trim((string) $row->legacy_name) !== trim((string) $row->canonical_name)
                || $this->nullableString($row->legacy_inventory_number ?? $row->legacy_asset_code) !== $this->nullableString($row->canonical_inventory_number)
                || (string) $row->legacy_ownership_type !== (string) $row->canonical_ownership_type
                || $this->nullableInt($row->legacy_machinery_id) !== $this->nullableInt($row->canonical_machinery_id)
                || $this->nullableInt($row->legacy_project_id) !== $this->nullableInt($row->canonical_project_id)
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
            ->distinct()
            ->get(['asset.id', 'asset.organization_id']);
        $candidateBuckets = ['none' => 0, 'one' => 0, 'many' => 0];
        foreach ($scopeMismatchAssets as $asset) {
            $projects = DB::table('projects')->where('organization_id', $asset->organization_id);
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
            ->distinct()
            ->orderBy('asset.id')
            ->get(['asset.id', 'asset.organization_id', 'asset.current_project_id', 'asset.current_schedule_task_id']);

        return $assets->map(function (object $asset): array {
            $assetId = (int) $asset->id;
            $organizationId = (int) $asset->organization_id;
            $localCandidates = DB::table('projects')->where('organization_id', $organizationId);
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
}
