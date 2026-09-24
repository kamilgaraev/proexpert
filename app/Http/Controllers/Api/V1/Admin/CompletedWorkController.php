<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTOs\CompletedWork\CompletedWorkDTO;
use App\DTOs\CompletedWork\CompletedWorkMaterialDTO;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Requests\Api\V1\Admin\CompletedWork\StoreCompletedWorkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\StoreCompletedWorkBulkRequest;
use App\Http\Requests\Api\V1\Admin\CompletedWork\UpdateCompletedWorkRequest;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkCollection;
use App\Http\Resources\Api\V1\Admin\CompletedWork\CompletedWorkResource;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Models\Contractor;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\CompletedWork\CompletedWorkFormOptionsService;
use App\Services\CompletedWork\CompletedWorkService;
use App\Services\CompletedWork\CompletedWorkWorkflowService;
use App\Services\Schedule\ScheduleTaskCompletedWorkService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class CompletedWorkController extends Controller
{
    public function __construct(
        protected CompletedWorkService $completedWorkService,
        protected ScheduleTaskCompletedWorkService $scheduleTaskService,
        protected CompletedWorkFactService $completedWorkFactService,
        protected CompletedWorkWorkflowService $completedWorkWorkflowService,
        protected CompletedWorkFormOptionsService $completedWorkFormOptionsService,
    ) {}

    public function formOptions(Request $request): JsonResponse
    {
        $project = ProjectContextMiddleware::getProject($request);
        $context = ProjectContextMiddleware::getProjectContext($request);
        $actor = $request->user();

        if (! $project || ! $context || ! $actor) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), Response::HTTP_FORBIDDEN);
        }

        return AdminResponse::success($this->completedWorkFormOptionsService->forProject($project, $actor, $context));
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $projectId = $request->route('project');
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            $project = ProjectContextMiddleware::getProject($request);
            $organizationId = $project?->organization_id ?? Auth::user()->current_organization_id;

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

            if ($projectContext && $this->projectContextRequiresOwnWorkScope($projectContext->role)) {
                $filters['contractor_id'] = $this->resolveProjectContractorId(
                    (int) $organizationId,
                    (int) $projectContext->organizationId
                ) ?? -1;
            }

            $sortBy = $request->query('sortBy', 'completion_date');
            $sortDirection = $request->query('sortDirection', 'desc');
            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

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
            $completedWork = $this->completedWorkService->create($dto, $projectContext, Auth::user());

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

    public function showProjectWork(int $project, CompletedWork $completed_work): JsonResponse
    {
        if ((int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        if (! $this->canAccessProjectWork($completed_work, request())) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        return AdminResponse::success(new CompletedWorkResource($this->loadWorkRelations($completed_work)));
    }

    public function confirmProjectWork(int $project, CompletedWork $completed_work): JsonResponse
    {
        if ((int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        if (! $this->canAccessProjectWork($completed_work, request())) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        /** @var \App\Models\User|null $actor */
        $actor = Auth::user();
        if (! $actor) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), 403);
        }

        try {
            $confirmedWork = $this->completedWorkWorkflowService->confirm($completed_work, $actor);

            return AdminResponse::success(
                new CompletedWorkResource($this->loadWorkRelations($confirmedWork)),
                trans_message('completed_work.confirmed')
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.confirm.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completed_work->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.confirm.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completed_work->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.confirm_error'), 500);
        }
    }

    public function updateProjectWork(UpdateCompletedWorkRequest $request, int $project, CompletedWork $completed_work): JsonResponse
    {
        if ((int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        if (! $this->canAccessProjectWork($completed_work, $request)) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        if ($projectContext && ! $projectContext->roleConfig->canManageWorks) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), 403);
        }

        try {
            $dto = $request->toDto();
            $updatedWork = $this->completedWorkService->update(
                $completed_work->id,
                $dto,
                Auth::user(),
                $projectContext,
            );

            return AdminResponse::success(
                new CompletedWorkResource($this->loadWorkRelations($updatedWork)),
                trans_message('completed_work.updated')
            );
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.update.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completed_work->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.update.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completed_work->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.update_error'), 500);
        }
    }

    public function destroyProjectWork(int $project, CompletedWork $completed_work): JsonResponse
    {
        if ((int) $completed_work->project_id !== $project) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        if (! $this->canAccessProjectWork($completed_work, request())) {
            return AdminResponse::error(trans_message('completed_work.not_found'), 404);
        }

        $projectContext = ProjectContextMiddleware::getProjectContext(request());
        if ($projectContext && ! $projectContext->roleConfig->canManageWorks) {
            return AdminResponse::error(trans_message('completed_work.forbidden'), 403);
        }

        try {
            $this->completedWorkService->delete(
                $completed_work->id,
                $completed_work->organization_id,
                Auth::user(),
                $projectContext,
            );

            return AdminResponse::success(null, trans_message('completed_work.deleted'), Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            Log::error('completed_work.destroy.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completed_work->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error($e->getMessage(), $e->getCode() ?: Response::HTTP_BAD_REQUEST);
        } catch (\Throwable $e) {
            Log::error('completed_work.destroy.error', [
                'error' => $e->getMessage(),
                'completed_work_id' => $completed_work->id,
                'user_id' => Auth::id(),
            ]);

            return AdminResponse::error(trans_message('completed_work.delete_error'), 500);
        }
    }

    public function getScheduleTasks(Request $request): JsonResponse
    {
        try {
            $projectId = (int) $request->route('project');
            $context = ProjectContextMiddleware::getProjectContext($request);
            $actor = $request->user();
            if (! $context || ! $actor) {
                return AdminResponse::error(trans_message('completed_work.forbidden'), Response::HTTP_FORBIDDEN);
            }

            $authorization = app(AuthorizationService::class);
            $canManageWorks = $context->roleConfig->canManageWorks
                && $authorization->can($actor, 'completed_works.create', [
                    'project_id' => $projectId,
                    'organization_id' => $context->organizationId,
                    'strict_project_scope' => true,
                ]);
            $canManageDefects = $authorization->can($actor, 'quality-control.defects.create', [
                'organization_id' => $context->organizationId,
            ]);
            if (! $canManageWorks && ! $canManageDefects) {
                return AdminResponse::error(trans_message('completed_work.forbidden'), Response::HTTP_FORBIDDEN);
            }

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

            if (! $task) {
                return AdminResponse::error(trans_message('completed_work.schedule_task_not_found'), 404);
            }

            $updatedWork = $this->completedWorkFactService->attachToTask($completedWork, $task, $request->user());

            return AdminResponse::success(
                new CompletedWorkResource($updatedWork),
                trans_message('completed_work.attached_to_schedule')
            );
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
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

            if (! $schedule) {
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
            ], trans_message('completed_work.schedule_task_created'), Response::HTTP_CREATED);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode());
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

    public function bulkCreate(StoreCompletedWorkBulkRequest $request): JsonResponse
    {
        try {
            $dtos = $request->toDtos();
            $projectContext = ProjectContextMiddleware::getProjectContext($request);
            $createdWorks = array_map(
                fn (CompletedWork $work) => $this->loadWorkRelations($work),
                $this->completedWorkService->createMany($dtos, Auth::user(), $projectContext),
            );

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

            $spreadsheet = new Spreadsheet;
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
                ], null, 'A'.$row);
                $row++;
            }

            foreach (range('A', 'Q') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filePath = storage_path('app/temp/completed_works_export_'.now()->format('Ymd_His').'.xlsx');
            if (! is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0777, true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            return response()->download(
                $filePath,
                'completed_works_'.now()->format('Y-m-d_H-i-s').'.xlsx',
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

    private function canAccessProjectWork(CompletedWork $completedWork, Request $request): bool
    {
        $projectContext = ProjectContextMiddleware::getProjectContext($request);
        $currentOrganizationId = Auth::user()?->current_organization_id;

        if ($currentOrganizationId && (int) $completedWork->organization_id === (int) $currentOrganizationId) {
            return true;
        }

        if (! $projectContext) {
            return false;
        }

        if (! $this->projectContextRequiresOwnWorkScope($projectContext->role)) {
            return true;
        }

        $contractorId = $this->resolveProjectContractorId(
            (int) $completedWork->organization_id,
            (int) $projectContext->organizationId
        );

        return $contractorId !== null && (int) $completedWork->contractor_id === $contractorId;
    }

    private function resolveProjectContractorId(int $ownerOrganizationId, int $sourceOrganizationId): ?int
    {
        return Contractor::query()
            ->where('organization_id', $ownerOrganizationId)
            ->where('source_organization_id', $sourceOrganizationId)
            ->value('id');
    }

    private function projectContextRequiresOwnWorkScope(ProjectOrganizationRole $role): bool
    {
        return in_array($role, [
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::SUBCONTRACTOR,
        ], true);
    }
}
