<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompletedWork\CompletedWorkService;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use App\Http\Requests\Api\V1\Admin\CompletedWork\StoreCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\UpdateCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\SyncCompletedWorkMaterialsRequest;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkCollection;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CompletedWorkController extends Controller
{
    protected CompletedWorkService $completedWorkService;
    protected ScheduleTaskCompletedWorkService $scheduleTaskService;

    public function __construct(
        CompletedWorkService $completedWorkService,
        ScheduleTaskCompletedWorkService $scheduleTaskService
    ) {
        $this->completedWorkService = $completedWorkService;
        $this->scheduleTaskService  = $scheduleTaskService;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = Auth::user()->current_organization_id;
            $projectId = $request->route('project');

            $filters = $request->only([
                'contract_id',
                'work_type_id',
                'user_id',
                'status',
                'completion_date_from',
                'completion_date_to',
                'amount_from',
                'amount_to',
                'quantity_from',
                'quantity_to',
                'with_materials',
                'contractor_id',
                'search',
            ]);

            $filters['organization_id'] = $organizationId;
            $filters['project_id'] = $projectId;

            $sortBy = $request->query('sortBy', 'completion_date');
            $sortDirection = $request->query('sortDirection', 'desc');
            $perPage = $request->query('per_page', 15);

            $completedWorks = $this->completedWorkService->getAll(
                $filters,
                $perPage,
                $sortBy,
                $sortDirection,
                ['project', 'contract.contractor', 'workType', 'user', 'contractor', 'materials.measurementUnit', 'scheduleTask.workType', 'scheduleTask.measurementUnit', 'scheduleTask.estimateItem.workType', 'scheduleTask.estimateItem.contractLinks.contract.contractor']
            );

            return AdminResponse::success(
                new CompletedWorkCollection($completedWorks)
            );
        } catch (\Exception $e) {
            Log::error('completed_work.index.error', [
                'error'      => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id'    => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при получении списка выполненных работ', 500);
        }
    }

    public function store(StoreCompletedWorkRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDto();
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            $completedWork = $this->completedWorkService->create($dto, $projectContext);

            return AdminResponse::success(
                new CompletedWorkResource($completedWork->load(['project', 'contract.contractor', 'workType', 'user', 'contractor', 'materials.measurementUnit', 'scheduleTask.workType', 'scheduleTask.measurementUnit', 'scheduleTask.estimateItem.workType', 'scheduleTask.estimateItem.contractLinks.contract.contractor'])),
                'Запись о выполненной работе успешно создана.',
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.store.error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('completed_work.store.error', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при создании выполненной работы', 500);
        }
    }

    public function show(CompletedWork $completedWork): JsonResponse
    {
        try {
            if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
                return AdminResponse::error('Запись о выполненной работе не найдена.', 404);
            }
            return AdminResponse::success(
                new CompletedWorkResource($completedWork->load(['project', 'contract.contractor', 'workType', 'user', 'contractor', 'materials.measurementUnit', 'files', 'scheduleTask.workType', 'scheduleTask.measurementUnit', 'scheduleTask.estimateItem.workType', 'scheduleTask.estimateItem.contractLinks.contract.contractor']))
            );
        } catch (\Exception $e) {
            Log::error('completed_work.show.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при получении выполненной работы', 500);
        }
    }

    public function update(UpdateCompletedWorkRequest $request, CompletedWork $completedWork): JsonResponse
    {
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            return AdminResponse::error('Это действие не авторизовано.', 403);
        }

        try {
            $dto = $request->toDto();
            $updatedWork = $this->completedWorkService->update($completedWork->id, $dto);
            return AdminResponse::success(
                new CompletedWorkResource($updatedWork->load(['project', 'contract.contractor', 'workType', 'user', 'contractor', 'materials.measurementUnit', 'scheduleTask.workType', 'scheduleTask.measurementUnit', 'scheduleTask.estimateItem.workType', 'scheduleTask.estimateItem.contractLinks.contract.contractor'])),
                'Запись о выполненной работе успешно обновлена.'
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.update.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('completed_work.update.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при обновлении выполненной работы', 500);
        }
    }

    public function destroy(CompletedWork $completedWork): JsonResponse
    {
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            return AdminResponse::error('Это действие не авторизовано.', 403);
        }

        try {
            $this->completedWorkService->delete($completedWork->id, $completedWork->organization_id);
            return AdminResponse::success(null, 'Выполненная работа удалена.', Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.destroy.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('completed_work.destroy.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при удалении выполненной работы', 500);
        }
    }

    public function syncMaterials(SyncCompletedWorkMaterialsRequest $request, CompletedWork $completedWork): JsonResponse
    {
        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            return AdminResponse::error('Это действие не авторизовано.', 403);
        }

        try {
            $materials = $request->getMaterialsArray();
            $updatedWork = $this->completedWorkService->syncCompletedWorkMaterials(
                $completedWork->id,
                $materials,
                $completedWork->organization_id
            );

            return AdminResponse::success(
                new CompletedWorkResource($updatedWork->load(['project', 'contract.contractor', 'workType', 'user', 'contractor', 'materials.measurementUnit', 'scheduleTask.workType', 'scheduleTask.measurementUnit', 'scheduleTask.estimateItem.workType', 'scheduleTask.estimateItem.contractLinks.contract.contractor'])),
                'Материалы выполненной работы успешно синхронизированы.'
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.sync_materials.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('completed_work.sync_materials.error', [
                'error'             => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id'           => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при синхронизации материалов', 500);
        }
    }

    public function getWorkTypeMaterialDefaults(Request $request): JsonResponse
    {
        $request->validate([
            'work_type_id' => 'required|integer|exists:work_types,id'
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $workTypeId = $request->input('work_type_id');

        try {
            $defaults = $this->completedWorkService->getWorkTypeMaterialDefaults($workTypeId, $organizationId);
            return AdminResponse::success($defaults);
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.material_defaults.error', [
                'error'        => $e->getMessage(),
                'work_type_id' => $workTypeId,
                'user_id'      => Auth::id(),
            ]);
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            Log::error('completed_work.material_defaults.error', [
                'error'        => $e->getMessage(),
                'work_type_id' => $workTypeId,
                'user_id'      => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при получении материалов по умолчанию', 500);
        }
    }

    public function getScheduleTasks(Request $request): JsonResponse
    {
        try {
            $projectId = $request->route('project');
            $scheduleId = $request->query('schedule_id');
            $search = $request->query('search');

            $tasks = $this->scheduleTaskService->getTasksForSelection(
                projectId: (int) $projectId,
                scheduleId: $scheduleId ? (int) $scheduleId : null,
                search: $search ?: null,
            );

            return AdminResponse::success(
                $tasks->map(fn($t) => [
                    'id'                 => $t->id,
                    'name'               => $t->name,
                    'wbs_code'           => $t->wbs_code,
                    'quantity'           => $t->quantity !== null ? (float) $t->quantity : null,
                    'completed_quantity' => $t->completed_quantity !== null ? (float) $t->completed_quantity : null,
                    'progress_percent'   => $t->progress_percent !== null ? (float) $t->progress_percent : null,
                    'planned_start_date' => $t->planned_start_date?->format('Y-m-d'),
                    'planned_end_date'   => $t->planned_end_date?->format('Y-m-d'),
                    'status'             => $t->status instanceof \BackedEnum ? $t->status->value : $t->status,
                    'schedule'           => $t->relationLoaded('schedule') ? [
                        'id'   => $t->schedule->id,
                        'name' => $t->schedule->name,
                    ] : null,
                    'measurement_unit'   => $t->relationLoaded('measurementUnit') && $t->measurementUnit ? [
                        'id'         => $t->measurementUnit->id,
                        'short_name' => $t->measurementUnit->short_name,
                    ] : null,
                ])->values()
            );
        } catch (\Exception $e) {
            Log::error('completed_work.get_schedule_tasks.error', [
                'error'      => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id'    => Auth::id(),
            ]);
            return AdminResponse::error('Ошибка при загрузке задач графика', 500);
        }
    }
}
