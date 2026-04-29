<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Requests\Api\V1\Admin\CompletedWork\StoreCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\SyncCompletedWorkMaterialsRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\UpdateCompletedWorkRequest;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkCollection;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\CompletedWork\CompletedWorkService;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use function trans_message;

class CompletedWorkController extends Controller
{
    public function __construct(
        protected CompletedWorkService $completedWorkService,
        protected ScheduleTaskCompletedWorkService $scheduleTaskService,
        protected CompletedWorkFactService $completedWorkFactService,
    ) {
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
                'planning_status',
                'work_origin_type',
                'search',
            ]);

            $filters['organization_id'] = $organizationId;
            $filters['project_id'] = $projectId;

            $sortBy = $request->query('sortBy', 'completion_date');
            $sortDirection = $request->query('sortDirection', 'desc');
            $perPage = (int) $request->query('per_page', 15);

            $completedWorks = $this->completedWorkService->getAll(
                $filters,
                $perPage,
                $sortBy,
                $sortDirection,
                [
                    'project',
                    'contract.contractor',
                    'workType',
                    'user',
                    'contractor',
                    'materials.measurementUnit',
                    'scheduleTask.schedule',
                    'scheduleTask.workType',
                    'scheduleTask.measurementUnit',
                    'scheduleTask.estimateItem.workType',
                    'scheduleTask.estimateItem.measurementUnit',
                    'scheduleTask.estimateItem.contractLinks.contract.contractor',
                    'estimateItem.measurementUnit',
                    'journalEntry',
                ]
            );

            return AdminResponse::success(new CompletedWorkCollection($completedWorks));
        } catch (\Throwable $e) {
            Log::error('completed_work.index.error', [
                'error' => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.list_error'), 500);
        }
    }

    public function store(StoreCompletedWorkRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDto();
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            $completedWork = $this->completedWorkService->create($dto, $projectContext);

            return AdminResponse::success(
                new CompletedWorkResource($this->loadWorkRelations($completedWork)),
                trans_message('completed_work.created'),
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.store.error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.store.error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.create_error'), 500);
        }
    }

    public function show(CompletedWork $completed_work): JsonResponse
    {
        $completedWork = $completed_work;

        try {
            if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
                return AdminResponse::error(trans_message('completed_work.not_found'), 404);
            }

            return AdminResponse::success(new CompletedWorkResource($this->loadWorkRelations($completedWork)));
        } catch (\Throwable $e) {
            Log::error('completed_work.show.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.load_error'), 500);
        }
    }

    public function update(UpdateCompletedWorkRequest $request, CompletedWork $completed_work): JsonResponse
    {
        $completedWork = $completed_work;

        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), 403);
        }

        try {
            $dto = $request->toDto();
            $updatedWork = $this->completedWorkService->update($completedWork->id, $dto);

            return AdminResponse::success(
                new CompletedWorkResource($this->loadWorkRelations($updatedWork)),
                trans_message('completed_work.updated')
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.update.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.update.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.update_error'), 500);
        }
    }

    public function destroy(CompletedWork $completed_work): JsonResponse
    {
        $completedWork = $completed_work;

        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), 403);
        }

        try {
            $this->completedWorkService->delete($completedWork->id, $completedWork->organization_id);

            return AdminResponse::success(null, trans_message('completed_work.deleted'), Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.destroy.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.destroy.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.delete_error'), 500);
        }
    }

    public function syncMaterials(SyncCompletedWorkMaterialsRequest $request, CompletedWork $completed_work): JsonResponse
    {
        $completedWork = $completed_work;

        if ($completedWork->organization_id !== Auth::user()->current_organization_id) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), 403);
        }

        try {
            $materials = $request->getMaterialsArray();
            $updatedWork = $this->completedWorkService->syncCompletedWorkMaterials(
                $completedWork->id,
                $materials,
                $completedWork->organization_id
            );

            return AdminResponse::success(
                new CompletedWorkResource($this->loadWorkRelations($updatedWork)),
                trans_message('completed_work.materials_synced')
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.sync_materials.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.sync_materials.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completedWork->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.materials_sync_error'), 500);
        }
    }

    public function getWorkTypeMaterialDefaults(Request $request): JsonResponse
    {
        $request->validate([
            'work_type_id' => 'required|integer|exists:work_types,id',
        ]);

        $organizationId = Auth::user()->current_organization_id;
        $workTypeId = (int) $request->input('work_type_id');

        try {
            $defaults = $this->completedWorkService->getWorkTypeMaterialDefaults($workTypeId, $organizationId);

            return AdminResponse::success($defaults);
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.material_defaults.error', [
                'error' => $e->getMessage(),
                'work_type_id' => $workTypeId,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.material_defaults.error', [
                'error' => $e->getMessage(),
                'work_type_id' => $workTypeId,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.material_defaults_error'), 500);
        }
    }

    public function getScheduleTasks(Request $request): JsonResponse
    {
        try {
            $projectId = (int) $request->route('project');
            $scheduleId = $request->query('schedule_id');
            $search = $request->query('search');

            $tasks = $this->scheduleTaskService->getTasksForSelection(
                projectId: $projectId,
                scheduleId: $scheduleId ? (int) $scheduleId : null,
                search: $search ?: null,
            );

            return AdminResponse::success(
                $tasks->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'wbs_code' => $t->wbs_code,
                    'quantity' => $t->quantity !== null ? (float) $t->quantity : null,
                    'completed_quantity' => $t->completed_quantity !== null ? (float) $t->completed_quantity : null,
                    'progress_percent' => $t->progress_percent !== null ? (float) $t->progress_percent : null,
                    'planned_start_date' => $t->planned_start_date?->format('Y-m-d'),
                    'planned_end_date' => $t->planned_end_date?->format('Y-m-d'),
                    'status' => $t->status instanceof \BackedEnum ? $t->status->value : $t->status,
                    'schedule' => $t->relationLoaded('schedule') ? [
                        'id' => $t->schedule->id,
                        'name' => $t->schedule->name,
                    ] : null,
                    'measurement_unit' => $t->relationLoaded('measurementUnit') && $t->measurementUnit ? [
                        'id' => $t->measurementUnit->id,
                        'short_name' => $t->measurementUnit->short_name,
                    ] : null,
                ])->values()
            );
        } catch (\Throwable $e) {
            Log::error('completed_work.get_schedule_tasks.error', [
                'error' => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.schedule_tasks_error'), 500);
        }
    }

    public function attachScheduleTask(Request $request, int $project, CompletedWork $completed_work): JsonResponse
    {
        $completedWork = $completed_work;

        if ($completedWork->organization_id !== Auth::user()->current_organization_id || $completedWork->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        $validated = $request->validate([
            'schedule_task_id' => ['required', 'integer'],
        ]);

        try {
            $task = ScheduleTask::query()
                ->where('id', (int) $validated['schedule_task_id'])
                ->whereHas('schedule', function ($query) use ($project): void {
                    $query->where('project_id', $project);
                })
                ->with('schedule')
                ->first();

            if (!$task) {
                return AdminResponse::error(trans_message('completed_work.schedule_task_not_found'), 404);
            }

            $updatedWork = $this->completedWorkFactService->attachToTask($completedWork, $task);

            return AdminResponse::success(
                new CompletedWorkResource($updatedWork),
                trans_message('completed_work.attached_to_schedule')
            );
        } catch (\Throwable $e) {
            Log::error('completed_work.attach_schedule_task.error', [
                'completed_work_id' => $completedWork->id,
                'project_id' => $project,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('completed_work.attach_schedule_error'), 500);
        }
    }

    public function createScheduleTaskFromWork(Request $request, int $project, CompletedWork $completed_work): JsonResponse
    {
        $completedWork = $completed_work;

        if ($completedWork->organization_id !== Auth::user()->current_organization_id || $completedWork->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        $validated = $request->validate([
            'schedule_id' => ['required', 'integer'],
        ]);

        try {
            $schedule = ProjectSchedule::query()
                ->where('id', (int) $validated['schedule_id'])
                ->where('project_id', $project)
                ->first();

            if (!$schedule) {
                return AdminResponse::error(trans_message('completed_work.schedule_not_found'), 404);
            }

            $task = $this->completedWorkFactService->createTaskFromWork($completedWork, $schedule, (int) Auth::id());
            $updatedWork = $this->loadWorkRelations($completedWork->fresh());

            return AdminResponse::success([
                'work' => new CompletedWorkResource($updatedWork),
                'task' => [
                    'id' => $task->id,
                    'name' => $task->name,
                    'schedule_id' => $task->schedule_id,
                    'progress_percent' => $task->progress_percent !== null ? (float) $task->progress_percent : null,
                    'completed_quantity' => $task->completed_quantity !== null ? (float) $task->completed_quantity : null,
                ],
            ], trans_message('completed_work.schedule_task_created'));
        } catch (\Throwable $e) {
            Log::error('completed_work.create_schedule_task.error', [
                'completed_work_id' => $completedWork->id,
                'project_id' => $project,
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('completed_work.schedule_task_create_error'), 500);
        }
    }

    public function bulkCreate(Request $request): JsonResponse
    {
        try {
            $projectId = (int) $request->route('project');
            $organizationId = (int) Auth::user()->current_organization_id;
            $worksPayload = $request->input('works', []);

            if (!is_array($worksPayload) || $worksPayload === []) {
                return AdminResponse::error(trans_message('completed_work.bulk_payload_required'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $createdWorks = [];
            $projectContext = ProjectContextMiddleware::getProjectContext($request);

            foreach ($worksPayload as $index => $workPayload) {
                $validator = Validator::make($workPayload, [
                    'work_type_id' => ['nullable', 'integer', 'exists:work_types,id'],
                    'user_id' => ['nullable', 'integer', 'exists:users,id'],
                    'schedule_task_id' => ['nullable', 'integer', 'exists:schedule_tasks,id'],
                    'estimate_item_id' => ['nullable', 'integer', 'exists:estimate_items,id'],
                    'contract_id' => ['nullable', 'integer', 'exists:contracts,id'],
                    'contractor_id' => ['nullable', 'integer', 'exists:contractors,id'],
                    'quantity' => ['required', 'numeric', 'min:0.001'],
                    'completed_quantity' => ['nullable', 'numeric', 'min:0'],
                    'price' => ['nullable', 'numeric', 'min:0'],
                    'total_amount' => ['nullable', 'numeric', 'min:0'],
                    'completion_date' => ['required', 'date_format:Y-m-d'],
                    'notes' => ['nullable', 'string'],
                    'status' => ['nullable', 'string', 'in:draft,pending,in_review,confirmed,cancelled,rejected'],
                    'work_origin_type' => ['nullable', 'string', 'in:manual,schedule,journal'],
                    'planning_status' => ['nullable', 'string', 'in:planned,requires_schedule'],
                    'additional_info' => ['nullable', 'array'],
                    'materials' => ['nullable', 'array'],
                    'materials.*.material_id' => ['required_with:materials', 'integer', 'exists:materials,id'],
                    'materials.*.quantity' => ['required_with:materials', 'numeric', 'min:0.0001'],
                    'materials.*.unit_price' => ['nullable', 'numeric', 'min:0'],
                    'materials.*.total_amount' => ['nullable', 'numeric', 'min:0'],
                    'materials.*.notes' => ['nullable', 'string', 'max:1000'],
                ]);

                if ($validator->fails()) {
                    return AdminResponse::error(
                        trans_message('completed_work.bulk_validation_error'),
                        Response::HTTP_UNPROCESSABLE_ENTITY,
                        ['index' => $index, 'errors' => $validator->errors()]
                    );
                }

                $validated = $validator->validated();
                $materials = isset($validated['materials'])
                    ? array_map(
                        fn (array $material) => CompletedWorkMaterialDTO::fromArray($material),
                        $validated['materials']
                    )
                    : null;

                $dto = new CompletedWorkDTO(
                    id: null,
                    organization_id: $organizationId,
                    project_id: $projectId,
                    schedule_task_id: $validated['schedule_task_id'] ?? null,
                    estimate_item_id: $validated['estimate_item_id'] ?? null,
                    journal_entry_id: null,
                    work_origin_type: $validated['work_origin_type'] ?? \App\Models\CompletedWork::ORIGIN_MANUAL,
                    planning_status: $validated['planning_status'] ?? (($validated['schedule_task_id'] ?? null)
                        ? \App\Models\CompletedWork::PLANNING_PLANNED
                        : \App\Models\CompletedWork::PLANNING_REQUIRES_SCHEDULE),
                    contract_id: $validated['contract_id'] ?? null,
                    contractor_id: $validated['contractor_id'] ?? null,
                    work_type_id: $validated['work_type_id'] ?? null,
                    user_id: $validated['user_id'] ?? null,
                    quantity: (float) $validated['quantity'],
                    completed_quantity: isset($validated['completed_quantity']) ? (float) $validated['completed_quantity'] : null,
                    price: isset($validated['price']) ? (float) $validated['price'] : null,
                    total_amount: isset($validated['total_amount']) ? (float) $validated['total_amount'] : null,
                    completion_date: Carbon::parse($validated['completion_date']),
                    notes: $validated['notes'] ?? null,
                    status: $validated['status'] ?? 'draft',
                    additional_info: $validated['additional_info'] ?? null,
                    materials: $materials,
                );

                $createdWorks[] = $this->loadWorkRelations(
                    $this->completedWorkService->create($dto, $projectContext)
                );
            }

            return AdminResponse::success(
                CompletedWorkResource::collection(collect($createdWorks)),
                trans_message('completed_work.bulk_created'),
                Response::HTTP_CREATED
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.bulk_create.error', [
                'error' => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.bulk_create.error', [
                'error' => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.bulk_create_error'), 500);
        }
    }

    public function exportExcel(Request $request): BinaryFileResponse|JsonResponse
    {
        try {
            $organizationId = (int) Auth::user()->current_organization_id;
            $projectId = (int) $request->route('project');

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
                'planning_status',
                'work_origin_type',
                'search',
            ]);

            $filters['organization_id'] = $organizationId;
            $filters['project_id'] = $projectId;

            $works = $this->completedWorkService->getAll(
                $filters,
                1000,
                'completion_date',
                'desc',
                [
                    'project',
                    'contract',
                    'workType',
                    'user',
                    'contractor',
                    'scheduleTask.schedule',
                    'estimateItem.measurementUnit',
                    'journalEntry',
                ]
            );

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                'ID',
                'Дата',
                'Статус',
                'Источник',
                'Планирование',
                'Вид работ',
                'Объем',
                'Выполнено',
                'Сумма',
                'Проект',
                'Задача графика',
                'График',
                'Запись журнала',
                'Позиция сметы',
                'Исполнитель',
                'Подрядчик',
                'Примечание',
            ], null, 'A1');

            $row = 2;
            foreach ($works->items() as $work) {
                $sheet->fromArray([
                    $work->id,
                    $work->completion_date?->format('Y-m-d'),
                    $work->status,
                    $work->work_origin_type,
                    $work->planning_status,
                    $work->workType?->name,
                    $work->quantity !== null ? (float) $work->quantity : null,
                    $work->completed_quantity !== null ? (float) $work->completed_quantity : null,
                    $work->total_amount !== null ? (float) $work->total_amount : null,
                    $work->project?->name,
                    $work->scheduleTask?->name,
                    $work->scheduleTask?->schedule?->name,
                    $work->journalEntry?->entry_number,
                    $work->estimateItem?->name,
                    $work->user?->name,
                    $work->contractor?->name,
                    $work->notes,
                ], null, 'A' . $row);
                $row++;
            }

            foreach (range('A', 'Q') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filePath = storage_path('app/temp/completed_works_export_' . now()->format('Ymd_His') . '.xlsx');
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            return response()->download(
                $filePath,
                'completed_works_' . now()->format('Y-m-d_H-i-s') . '.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error('completed_work.export_excel.error', [
                'error' => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.export_error'), 500);
        }
    }

    private function loadWorkRelations(CompletedWork $completedWork): CompletedWork
    {
        return $completedWork->load([
            'project',
            'contract.contractor',
            'workType',
            'user',
            'contractor',
            'materials.measurementUnit',
            'scheduleTask.schedule',
            'scheduleTask.workType',
            'scheduleTask.measurementUnit',
            'scheduleTask.estimateItem.workType',
            'scheduleTask.estimateItem.measurementUnit',
            'scheduleTask.estimateItem.contractLinks.contract.contractor',
            'estimateItem.measurementUnit',
            'journalEntry',
        ]);
    }
}
