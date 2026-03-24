<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Controllers;

use App\BusinessModules\Features\ScheduleManagement\Http\Requests\CreateScheduleFromEstimateRequest;
use App\BusinessModules\Features\ScheduleManagement\Http\Requests\SyncScheduleWithEstimateRequest;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\EstimateSyncStatusResource;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\ScheduleWithEstimateResource;
use App\BusinessModules\Features\ScheduleManagement\Services\EstimateScheduleImportService;
use App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Estimate;
use App\Models\ProjectSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class ScheduleEstimateController extends Controller
{
    public function __construct(
        private readonly EstimateScheduleImportService $importService,
        private readonly EstimateSyncService $syncService
    ) {
    }

    public function createFromEstimate(CreateScheduleFromEstimateRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            $accessController = app(\App\Modules\Core\AccessController::class);
            if (!$accessController->hasModuleAccess($organizationId, 'budget-estimates')) {
                return AdminResponse::error(
                    trans_message('schedule_management.estimate_module_inactive'),
                    Response::HTTP_FORBIDDEN
                );
            }

            $validated = $request->validated();
            $estimate = Estimate::query()
                ->where('id', $validated['estimate_id'])
                ->where('organization_id', $organizationId)
                ->first();

            if (!$estimate) {
                return AdminResponse::error(
                    trans_message('schedule_management.estimate_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $schedule = $this->importService->createScheduleFromEstimate(
                $estimate,
                $validated['options'] ?? []
            );

            return AdminResponse::success(
                new ScheduleWithEstimateResource($schedule),
                trans_message('schedule_management.schedule_created_from_estimate'),
                Response::HTTP_CREATED
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'createFromEstimate',
                $e,
                $request,
                trans_message('schedule_management.schedule_create_from_estimate_error')
            );
        }
    }

    public function syncFromEstimate(SyncScheduleWithEstimateRequest $request, int $scheduleId): JsonResponse
    {
        try {
            $schedule = $this->findScheduleForOrganization(
                (int) $request->attributes->get('current_organization_id'),
                $scheduleId
            );

            if (!$schedule) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (!$schedule->estimate_id) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_estimate_missing'),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $results = $this->syncService->syncScheduleWithEstimate(
                $schedule,
                (bool) ($request->validated()['force'] ?? false)
            );

            return AdminResponse::success([
                'schedule' => new ScheduleWithEstimateResource($schedule->fresh(['estimate', 'tasks'])),
                'sync_results' => $results,
            ], trans_message('schedule_management.schedule_synced_from_estimate'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'syncFromEstimate',
                $e,
                $request,
                trans_message('schedule_management.schedule_sync_from_estimate_error'),
                ['schedule_id' => $scheduleId]
            );
        }
    }

    public function syncToEstimate(Request $request, int $scheduleId): JsonResponse
    {
        try {
            $schedule = $this->findScheduleForOrganization(
                (int) $request->attributes->get('current_organization_id'),
                $scheduleId
            );

            if (!$schedule) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (!$schedule->estimate_id) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_estimate_missing'),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            return AdminResponse::success(
                $this->syncService->syncEstimateProgress($schedule),
                trans_message('schedule_management.schedule_synced_to_estimate')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'syncToEstimate',
                $e,
                $request,
                trans_message('schedule_management.schedule_sync_to_estimate_error'),
                ['schedule_id' => $scheduleId]
            );
        }
    }

    public function getConflicts(Request $request, int $scheduleId): JsonResponse
    {
        try {
            $schedule = $this->findScheduleForOrganization(
                (int) $request->attributes->get('current_organization_id'),
                $scheduleId
            );

            if (!$schedule) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (!$schedule->estimate_id) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_estimate_missing'),
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }

            $conflicts = $this->syncService->detectConflicts($schedule);

            return AdminResponse::success([
                'conflicts_count' => count($conflicts),
                'conflicts' => $conflicts,
                'has_conflicts' => count($conflicts) > 0,
            ]);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'getConflicts',
                $e,
                $request,
                trans_message('schedule_management.estimate_conflicts_load_error'),
                ['schedule_id' => $scheduleId]
            );
        }
    }

    public function getEstimateInfo(Request $request, int $scheduleId): JsonResponse
    {
        try {
            $schedule = ProjectSchedule::query()
                ->where('id', $scheduleId)
                ->where('organization_id', (int) $request->attributes->get('current_organization_id'))
                ->with('estimate')
                ->first();

            if (!$schedule) {
                return AdminResponse::error(
                    trans_message('schedule_management.schedule_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if (!$schedule->estimate_id) {
                return AdminResponse::success([
                    'has_estimate' => false,
                    'estimate' => null,
                    'sync_status' => null,
                ]);
            }

            return AdminResponse::success([
                'has_estimate' => true,
                'estimate' => [
                    'id' => $schedule->estimate->id,
                    'number' => $schedule->estimate->number,
                    'name' => $schedule->estimate->name,
                    'status' => $schedule->estimate->status,
                    'total_amount' => $schedule->estimate->total_amount,
                    'updated_at' => $schedule->estimate->updated_at,
                ],
                'sync_status' => new EstimateSyncStatusResource($schedule),
            ]);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'getEstimateInfo',
                $e,
                $request,
                trans_message('schedule_management.estimate_info_load_error'),
                ['schedule_id' => $scheduleId]
            );
        }
    }

    private function findScheduleForOrganization(int $organizationId, int $scheduleId): ?ProjectSchedule
    {
        return ProjectSchedule::query()
            ->where('id', $scheduleId)
            ->where('organization_id', $organizationId)
            ->first();
    }

    private function handleUnexpectedError(
        string $action,
        \Throwable $e,
        Request $request,
        string $message,
        array $context = []
    ): JsonResponse {
        Log::error("[ScheduleEstimateController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            ...$context,
        ]);

        return AdminResponse::error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
