<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklistItem;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackage;
use App\BusinessModules\Features\HandoverAcceptance\Models\HandoverPackageDocument;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyIncident;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspection;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyInspectionFinding;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyViolation;
use App\BusinessModules\Features\SafetyManagement\Models\SafetyWorkPermit;
use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlan;
use App\BusinessModules\Features\ScheduleManagement\Models\DailyWorkPlanAssignment;
use App\BusinessModules\Features\ScheduleManagement\Models\WorkConstraint;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Responses\MobileResponse;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Models\User;
use App\Services\Mobile\MobileProjectAccessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class MobileProjectAuthorizeMiddleware
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function handle(Request $request, Closure $next, string $permissions, string $source = 'project_id', ?string $routeParam = null): Response
    {
        $user = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if (! $user instanceof User || $organizationId <= 0) {
            return MobileResponse::error(trans_message('errors.unauthorized'), 403);
        }

        $projectId = $this->projectId($request, $source, $organizationId, $routeParam);
        if ($projectId === false) {
            return MobileResponse::error(trans_message('errors.unauthorized'), 403);
        }

        if ($projectId === null) {
            $context = [
                'organization_id' => $organizationId,
            ];
        } else {
            try {
                $this->projectAccess->resolve(
                    $user,
                    $organizationId,
                    $projectId,
                    trans_message('errors.unauthorized'),
                );
            } catch (\DomainException) {
                return MobileResponse::error(trans_message('errors.unauthorized'), 403);
            }

            $context = [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ];
        }

        $allowed = collect(explode('|', $permissions))
            ->map(static fn (string $permission): string => trim($permission))
            ->filter()
            ->contains(fn (string $permission): bool => $this->authorization->can($user, $permission, $context));

        return $allowed
            ? $next($request)
            : MobileResponse::error(trans_message('errors.unauthorized'), 403);
    }

    private function projectId(Request $request, string $source, int $organizationId, ?string $routeParam): int|false|null
    {
        if ($source === 'project_id') {
            $value = $request->input('project_id');
            if ($value === null || $value === '') {
                return null;
            }

            return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: false;
        }

        if ($source === 'qr_token') {
            $token = trim((string) $request->input('qr_token'));
            if ($token === '') {
                return false;
            }

            $resource = DB::table('workforce_attendance_qr_tokens')
                ->where('organization_id', $organizationId)
                ->where('token_hash', hash_hmac('sha256', $token, (string) config('app.key')))
                ->first();
            if ($resource === null) {
                return false;
            }
        } elseif (in_array($source, ['warehouse_id', 'from_warehouse_id', 'custody_warehouse_id', 'project_warehouse_id'], true)) {
            $warehouseId = $request->input($source);
            if ($warehouseId === null && $source === 'warehouse_id' && $routeParam !== null) {
                $warehouseId = $request->route($routeParam);
            }
            $warehouseKey = $this->positiveInteger($warehouseId);
            if ($warehouseKey === false) {
                return false;
            }

            $warehouse = OrganizationWarehouse::query()
                ->where('organization_id', $organizationId)
                ->whereKey($warehouseKey)
                ->first();

            if ($warehouse === null) {
                return false;
            }

            $resource = $warehouse;

            if ($source === 'from_warehouse_id' && $request->filled('to_warehouse_id')) {
                $destinationId = $this->positiveInteger($request->input('to_warehouse_id'));
                if ($destinationId === false) {
                    return false;
                }
                $destination = OrganizationWarehouse::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($destinationId)
                    ->first();

                if ($destination === null) {
                    return false;
                }

                if (($warehouse->project_id === null) !== ($destination->project_id === null)
                    || ($warehouse->project_id !== null && (int) $warehouse->project_id !== (int) $destination->project_id)) {
                    return null;
                }
            }
        } elseif ($source === 'warehouse_route_id') {
            $key = $this->routeId($request, $routeParam);
            if ($key === false) {
                return false;
            }
            $resource = OrganizationWarehouse::query()
                ->where('organization_id', $organizationId)
                ->whereKey($key)
                ->first();
            if ($resource === null) {
                return false;
            }
        } elseif ($source === 'delivery_id') {
            $key = $this->routeId($request, $routeParam);
            if ($key === false) {
                return false;
            }
            $resource = ProjectMaterialDelivery::query()
                ->where('organization_id', $organizationId)
                ->whereKey($key)
                ->first();
            if ($resource === null) {
                return false;
            }
        } else {
            $key = $this->routeId($request, $routeParam);
            if ($key === false) {
                return false;
            }

            $resource = match ($source) {
                'shift_report' => MachineryShiftReport::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'maintenance_order' => MachineryMaintenanceOrder::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'quality_defect' => QualityDefect::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'safety_work_permit' => SafetyWorkPermit::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'safety_briefing' => SafetyBriefing::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'safety_violation' => SafetyViolation::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'safety_incident' => SafetyIncident::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'safety_inspection' => SafetyInspection::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'safety_inspection_finding' => SafetyInspectionFinding::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'project_schedule' => ProjectSchedule::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'schedule_task' => $this->scheduleForTask($key, $organizationId),
                'daily_plan' => DailyWorkPlan::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'daily_plan_assignment' => DailyWorkPlanAssignment::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'work_constraint' => WorkConstraint::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'handover_scope' => AcceptanceScope::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'handover_session' => AcceptanceSession::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'handover_finding' => AcceptanceFinding::query()->where('organization_id', $organizationId)->whereKey($key)->first(),
                'handover_checklist_item' => $this->checklistForItem($key, $organizationId),
                'handover_package_document' => $this->packageForDocument($key, $organizationId),
                default => false,
            };

            if ($resource === false || $resource === null) {
                return false;
            }
        }

        if ($resource === null) {
            return null;
        }

        $projectId = $resource->project_id !== null ? (int) $resource->project_id : null;
        $requestedProjectId = $request->input('project_id');

        if ($requestedProjectId !== null && $requestedProjectId !== '') {
            $requestedProjectKey = $this->positiveInteger($requestedProjectId);
            if ($requestedProjectKey === false || $projectId === null || $requestedProjectKey !== $projectId) {
                return false;
            }
        }

        return $projectId;
    }

    private function routeId(Request $request, ?string $routeParam): int|false
    {
        if ($routeParam === null || $routeParam === '') {
            return false;
        }

        return $this->positiveInteger($request->route($routeParam));
    }

    private function positiveInteger(mixed $value): int|false
    {
        $key = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $key === false ? false : $key;
    }

    private function scheduleForTask(int $id, int $organizationId): ?ProjectSchedule
    {
        $task = ScheduleTask::query()->where('organization_id', $organizationId)->whereKey($id)->first();
        if ($task === null) {
            return null;
        }

        return ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->whereKey($task->schedule_id)
            ->first();
    }

    private function checklistForItem(int $id, int $organizationId): ?AcceptanceScope
    {
        $item = AcceptanceChecklistItem::query()
            ->whereKey($id)
            ->whereHas('checklist', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->whereHas('scope', fn ($scope) => $scope->where('organization_id', $organizationId)))
            ->with('checklist.scope')
            ->first();

        $checklist = $item?->checklist;
        $scope = $checklist?->scope;

        if ($scope === null || (int) $checklist->project_id !== (int) $scope->project_id) {
            return null;
        }

        return $scope;
    }

    private function packageForDocument(int $id, int $organizationId): ?HandoverPackage
    {
        $document = HandoverPackageDocument::query()
            ->whereKey($id)
            ->whereHas('package', fn ($query) => $query->where('organization_id', $organizationId))
            ->with('package')
            ->first();

        return $document?->package;
    }
}
