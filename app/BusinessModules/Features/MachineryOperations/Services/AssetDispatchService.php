<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Core\AssetManagement\Models\OrganizationAsset;
use App\BusinessModules\Features\MachineryOperations\DTO\AssetRequestData;
use App\BusinessModules\Features\MachineryOperations\DTO\AssignmentData;
use App\BusinessModules\Features\MachineryOperations\Models\AssetRequest;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAsset;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AssetDispatchService
{
    public function __construct(
        private MachineryOperationsService $operations,
        private AuthorizationService $authorization,
    ) {}

    public function request(int $organizationId, int $actorId, AssetRequestData $data): AssetRequest
    {
        $this->assertProject($data->projectId, $organizationId);
        $this->assertScheduleTask($data->scheduleTaskId, $organizationId);

        return DB::transaction(function () use ($organizationId, $actorId, $data): AssetRequest {
            $request = AssetRequest::query()->create([
                'organization_id' => $organizationId,
                'project_id' => $data->projectId,
                'schedule_task_id' => $data->scheduleTaskId,
                'requested_by_user_id' => $actorId,
                'status' => 'pending',
                'priority' => $data->priority,
                'planned_start_at' => $data->plannedStartAt,
                'planned_end_at' => $data->plannedEndAt,
                'required_profile' => $data->requiredProfile,
                'purpose' => $data->purpose,
            ]);
            $this->event($request, $actorId, 'requested', [
                'project_id' => $data->projectId,
                'planned_start_at' => $data->plannedStartAt,
                'planned_end_at' => $data->plannedEndAt,
            ]);

            return $request->load('events');
        });
    }

    public function paginateRequests(int $organizationId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        return AssetRequest::forOrganization($organizationId)
            ->with(['project:id,name', 'organizationAsset:id,name,inventory_number,technical_status'])
            ->withCount('events')
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END")
            ->orderBy('planned_start_at')
            ->paginate(min(max($perPage, 1), 100));
    }

    /** @return array<string, int> */
    public function overview(int $organizationId): array
    {
        return [
            'open_downtimes' => DB::table('machinery_downtimes')->where('organization_id', $organizationId)->whereNull('ended_at')->count(),
            'pending_requests' => DB::table('asset_requests')->where('organization_id', $organizationId)->whereIn('status', ['pending', 'approved'])->whereNull('deleted_at')->count(),
            'shift_variances' => DB::table('machinery_shift_reports')->where('organization_id', $organizationId)->where('status', 'submitted')->whereColumn('actual_hours', '<>', 'planned_hours')->whereNull('deleted_at')->count(),
            'overdue_maintenance' => DB::table('machinery_maintenance_orders')->where('organization_id', $organizationId)->whereIn('status', ['open', 'in_progress'])->where('planned_at', '<', now())->whereNull('deleted_at')->count(),
        ];
    }

    public function assign(int $organizationId, int $actorId, AssignmentData $data): MachineryAssignment
    {
        return $this->performAssignment($organizationId, $actorId, $data, false, null);
    }

    public function directAssign(int $organizationId, int $actorId, AssignmentData $data, string $reason): MachineryAssignment
    {
        $actor = User::query()->find($actorId);
        if ($actor === null || ! $this->authorization->can($actor, 'machinery-operations.direct_assign', [
            'organization_id' => $organizationId,
        ])) {
            throw new DomainException(trans_message('machinery_operations.errors.direct_assign_forbidden'));
        }
        if (trim($reason) === '') {
            throw new DomainException(trans_message('machinery_operations.errors.direct_assign_reason_required'));
        }

        return $this->performAssignment($organizationId, $actorId, $data, true, trim($reason));
    }

    /**
     * @return Collection<int, array{asset: MachineryAsset, eligible: bool, exclusion_reasons: list<string>, score: float, distance_km: float|null}>
     */
    public function candidates(AssetRequest $request): Collection
    {
        $request->loadMissing('project');

        return MachineryAsset::forOrganization((int) $request->organization_id)
            ->whereNotNull('organization_asset_id')
            ->with(['organizationAsset.operationProfile', 'organizationAsset.currentProject'])
            ->get()
            ->map(function (MachineryAsset $legacy) use ($request): array {
                $canonical = $legacy->organizationAsset;
                $reasons = $canonical === null ? ['canonical_link_missing'] : $this->exclusionReasons($canonical, $request);
                $distance = $canonical === null ? null : $this->distanceKm($request->project, $canonical->currentProject);
                $sameProject = $canonical?->current_project_id === $request->project_id;
                $cost = (float) $legacy->operating_cost_per_hour;
                $score = ($sameProject ? 100000.0 : 0.0) - (($distance ?? 1000.0) * 100.0) - $cost;

                return [
                    'asset' => $legacy,
                    'eligible' => $reasons === [],
                    'exclusion_reasons' => $reasons,
                    'score' => $score,
                    'distance_km' => $distance,
                ];
            })
            ->sortBy([
                ['eligible', 'desc'],
                ['score', 'desc'],
            ])
            ->values();
    }

    private function performAssignment(
        int $organizationId,
        int $actorId,
        AssignmentData $data,
        bool $direct,
        ?string $reason,
    ): MachineryAssignment {
        return DB::transaction(function () use ($organizationId, $actorId, $data, $direct, $reason): MachineryAssignment {
            $legacy = MachineryAsset::forOrganization($organizationId)
                ->where('organization_asset_id', $data->organizationAssetId)
                ->lockForUpdate()
                ->first();
            if ($legacy === null) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_not_found'));
            }

            $canonical = OrganizationAsset::forOrganization($organizationId)
                ->whereKey($data->organizationAssetId)
                ->lockForUpdate()
                ->first();
            if ($canonical === null || $canonical->lifecycle_status !== AssetLifecycleStatus::Active) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_not_found'));
            }
            if ($canonical->technical_status !== AssetTechnicalStatus::Serviceable) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_not_serviceable'));
            }

            $request = $data->assetRequestId === null ? null : AssetRequest::forOrganization($organizationId)
                ->whereKey($data->assetRequestId)
                ->lockForUpdate()
                ->first();
            if ($direct && $request === null) {
                $request = AssetRequest::query()->create([
                    'organization_id' => $organizationId,
                    'project_id' => $data->projectId,
                    'schedule_task_id' => $data->scheduleTaskId,
                    'requested_by_user_id' => $actorId,
                    'approved_by_user_id' => $actorId,
                    'status' => 'approved',
                    'priority' => 'urgent',
                    'planned_start_at' => $data->plannedStartAt,
                    'planned_end_at' => $data->plannedEndAt,
                    'required_profile' => [],
                    'purpose' => (string) $reason,
                    'decision_comment' => $reason,
                ]);
                $this->event($request, $actorId, 'direct_requested', ['reason' => $reason]);
            }
            if (! $direct && $request === null) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_request_required'));
            }
            if ($request !== null && ! in_array($request->status, ['pending', 'approved'], true)) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_request_closed'));
            }
            if ($request !== null && (int) $request->project_id !== $data->projectId) {
                throw new DomainException(trans_message('machinery_operations.errors.asset_project_mismatch'));
            }

            $this->assertNoOverlap($data);
            if ($data->replacesAssignmentId !== null) {
                $this->replaceAssignment($organizationId, $data->replacesAssignmentId, $actorId, $reason);
            }

            $assignment = $this->operations->assignAsset($legacy, $actorId, [
                'project_id' => $data->projectId,
                'schedule_task_id' => $data->scheduleTaskId,
                'planned_start_at' => $data->plannedStartAt,
                'planned_end_at' => $data->plannedEndAt,
                'planned_hours' => $data->plannedHours,
                'comment' => $data->comment,
            ]);

            if ($request !== null) {
                $request->update([
                    'status' => 'assigned',
                    'approved_by_user_id' => $actorId,
                    'organization_asset_id' => $data->organizationAssetId,
                    'decision_comment' => $reason,
                ]);
                $this->event($request, $actorId, $direct ? 'direct_assigned' : 'assigned', [
                    'assignment_id' => $assignment->id,
                    'organization_asset_id' => $data->organizationAssetId,
                    'reason' => $reason,
                    'replaces_assignment_id' => $data->replacesAssignmentId,
                ]);
            }

            return $assignment;
        });
    }

    private function assertNoOverlap(AssignmentData $data): void
    {
        $overlap = MachineryAssignment::query()
            ->where('organization_asset_id', $data->organizationAssetId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($data->plannedEndAt !== null, fn ($query) => $query->where('planned_start_at', '<', $data->plannedEndAt))
            ->where(fn ($query) => $query->whereNull('planned_end_at')->orWhere('planned_end_at', '>', $data->plannedStartAt))
            ->when($data->replacesAssignmentId !== null, fn ($query) => $query->whereKeyNot($data->replacesAssignmentId))
            ->exists();

        if ($overlap) {
            throw new DomainException(trans_message('machinery_operations.errors.assignment_period_overlap'));
        }
    }

    private function replaceAssignment(int $organizationId, int $assignmentId, int $actorId, ?string $reason): void
    {
        $assignment = MachineryAssignment::forOrganization($organizationId)->whereKey($assignmentId)->lockForUpdate()->first();
        if ($assignment === null || $assignment->status !== 'active') {
            throw new DomainException(trans_message('machinery_operations.errors.assignment_not_replaceable'));
        }
        $assignment->update([
            'status' => 'replaced',
            'actual_end_at' => now(),
            'comment' => trim(($assignment->comment ? $assignment->comment."\n" : '').'Replacement: '.($reason ?? '')),
        ]);
    }

    /** @return list<string> */
    private function exclusionReasons(OrganizationAsset $asset, AssetRequest $request): array
    {
        $reasons = [];
        if ($asset->lifecycle_status !== AssetLifecycleStatus::Active) {
            $reasons[] = 'not_active';
        }
        if ($asset->technical_status !== AssetTechnicalStatus::Serviceable) {
            $reasons[] = 'not_serviceable';
        }
        $required = is_array($request->required_profile) ? $request->required_profile : [];
        $profile = $asset->operationProfile;
        foreach ($required as $field => $value) {
            $actual = $profile?->{$field};
            $actual = $actual instanceof \BackedEnum ? $actual->value : $actual;
            if ($actual !== $value) {
                $reasons[] = 'profile_'.$field;
            }
        }
        $overlap = MachineryAssignment::query()
            ->where('organization_asset_id', $asset->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->when($request->planned_end_at !== null, fn ($query) => $query->where('planned_start_at', '<', $request->planned_end_at))
            ->where(fn ($query) => $query->whereNull('planned_end_at')->orWhere('planned_end_at', '>', $request->planned_start_at))
            ->exists();
        if ($overlap) {
            $reasons[] = 'period_overlap';
        }

        return $reasons;
    }

    private function distanceKm(?Project $from, ?Project $to): ?float
    {
        if ($from?->latitude === null || $from->longitude === null || $to?->latitude === null || $to->longitude === null) {
            return null;
        }
        $lat1 = deg2rad((float) $from->latitude);
        $lat2 = deg2rad((float) $to->latitude);
        $deltaLat = $lat2 - $lat1;
        $deltaLon = deg2rad((float) $to->longitude - (float) $from->longitude);
        $a = sin($deltaLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        return round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    private function assertProject(int $projectId, int $organizationId): void
    {
        if (! Project::query()->accessibleByOrganization($organizationId)->whereKey($projectId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.project_not_found'));
        }
    }

    private function assertScheduleTask(?int $taskId, int $organizationId): void
    {
        if ($taskId !== null && ! ScheduleTask::query()->whereKey($taskId)->where('organization_id', $organizationId)->exists()) {
            throw new DomainException(trans_message('machinery_operations.errors.schedule_task_not_found'));
        }
    }

    /** @param array<string, mixed> $payload */
    private function event(AssetRequest $request, int $actorId, string $type, array $payload): void
    {
        $request->events()->create([
            'organization_id' => $request->organization_id,
            'actor_user_id' => $actorId,
            'event_type' => $type,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
