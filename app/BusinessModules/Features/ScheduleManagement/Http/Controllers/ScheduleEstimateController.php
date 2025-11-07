<?php

namespace App\BusinessModules\Features\ScheduleManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\ScheduleManagement\Services\EstimateScheduleImportService;
use App\BusinessModules\Features\ScheduleManagement\Services\EstimateSyncService;
use App\BusinessModules\Features\ScheduleManagement\Http\Requests\CreateScheduleFromEstimateRequest;
use App\BusinessModules\Features\ScheduleManagement\Http\Requests\SyncScheduleWithEstimateRequest;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\ScheduleWithEstimateResource;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\EstimateSyncStatusResource;
use App\Models\ProjectSchedule;
use App\Models\Estimate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ScheduleEstimateController extends Controller
{
    public function __construct(
        private readonly EstimateScheduleImportService $importService,
        private readonly EstimateSyncService $syncService
    ) {}

    /**
     * Создать график работ из сметы
     * 
     * @group Schedule Estimate Integration
     * @authenticated
     */
    public function createFromEstimate(CreateScheduleFromEstimateRequest $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            // Проверяем доступ к модулю смет
            $accessController = app(\App\Modules\Core\AccessController::class);
            if (!$accessController->hasModuleAccess($organizationId, 'budget-estimates')) {
                return response()->json([
                    'success' => false,
                    'error' => 'Модуль смет не активен для вашей организации',
                ], 403);
            }

            $validated = $request->validated();
            
            // Получаем смету
            $estimate = Estimate::where('id', $validated['estimate_id'])
                ->where('organization_id', $organizationId)
                ->first();

            if (!$estimate) {
                return response()->json([
                    'success' => false,
                    'error' => 'Смета не найдена',
                ], 404);
            }

            // Создаем график из сметы
            $schedule = $this->importService->createScheduleFromEstimate(
                $estimate,
                $validated['options'] ?? []
            );

            return response()->json([
                'success' => true,
                'message' => 'График работ успешно создан из сметы',
                'data' => new ScheduleWithEstimateResource($schedule),
            ], 201);

        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('schedule.create_from_estimate.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось создать график из сметы',
            ], 500);
        }
    }

    /**
     * Синхронизировать график со сметой (обновить данные из сметы)
     * 
     * @group Schedule Estimate Integration
     * @authenticated
     */
    public function syncFromEstimate(
        SyncScheduleWithEstimateRequest $request,
        int $scheduleId
    ): JsonResponse {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $schedule = ProjectSchedule::where('id', $scheduleId)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$schedule) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не найден',
                ], 404);
            }

            if (!$schedule->estimate_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не связан со сметой',
                ], 422);
            }

            $validated = $request->validated();
            $force = $validated['force'] ?? false;

            // Синхронизируем
            $results = $this->syncService->syncScheduleWithEstimate($schedule, $force);

            return response()->json([
                'success' => true,
                'message' => 'Синхронизация выполнена успешно',
                'data' => [
                    'schedule' => new ScheduleWithEstimateResource($schedule->fresh(['estimate', 'tasks'])),
                    'sync_results' => $results,
                ],
            ]);

        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('schedule.sync_from_estimate.error', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось синхронизировать график со сметой',
            ], 500);
        }
    }

    /**
     * Синхронизировать прогресс выполнения в смету
     * 
     * @group Schedule Estimate Integration
     * @authenticated
     */
    public function syncToEstimate(Request $request, int $scheduleId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $schedule = ProjectSchedule::where('id', $scheduleId)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$schedule) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не найден',
                ], 404);
            }

            if (!$schedule->estimate_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не связан со сметой',
                ], 422);
            }

            // Синхронизируем прогресс
            $results = $this->syncService->syncEstimateProgress($schedule);

            return response()->json([
                'success' => true,
                'message' => 'Прогресс выполнения синхронизирован в смету',
                'data' => $results,
            ]);

        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('schedule.sync_to_estimate.error', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось синхронизировать прогресс в смету',
            ], 500);
        }
    }

    /**
     * Получить конфликты между графиком и сметой
     * 
     * @group Schedule Estimate Integration
     * @authenticated
     */
    public function getConflicts(Request $request, int $scheduleId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $schedule = ProjectSchedule::where('id', $scheduleId)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$schedule) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не найден',
                ], 404);
            }

            if (!$schedule->estimate_id) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не связан со сметой',
                ], 422);
            }

            // Обнаруживаем конфликты
            $conflicts = $this->syncService->detectConflicts($schedule);

            return response()->json([
                'success' => true,
                'data' => [
                    'conflicts_count' => count($conflicts),
                    'conflicts' => $conflicts,
                    'has_conflicts' => count($conflicts) > 0,
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('schedule.get_conflicts.error', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось проверить конфликты',
            ], 500);
        }
    }

    /**
     * Получить информацию о связанной смете
     * 
     * @group Schedule Estimate Integration
     * @authenticated
     */
    public function getEstimateInfo(Request $request, int $scheduleId): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $schedule = ProjectSchedule::where('id', $scheduleId)
                ->where('organization_id', $organizationId)
                ->with('estimate')
                ->first();

            if (!$schedule) {
                return response()->json([
                    'success' => false,
                    'error' => 'График не найден',
                ], 404);
            }

            if (!$schedule->estimate_id) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'has_estimate' => false,
                        'estimate' => null,
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => [
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
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('schedule.get_estimate_info.error', [
                'schedule_id' => $scheduleId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось получить информацию о смете',
            ], 500);
        }
    }
}

