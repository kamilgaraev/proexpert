<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Schedule;

use App\Exceptions\Schedule\CircularDependencyException;
use App\Exceptions\Schedule\ScheduleNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasScheduleOperations;
use App\Http\Middleware\ProjectContextMiddleware;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Requests\Api\V1\Schedule\CreateTaskDependencyRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateTaskDependencyRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ScheduleGanttResource;
use App\Http\Responses\AdminResponse;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Services\Schedule\GanttExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use function trans_message;

class ProjectScheduleController extends Controller
{
    use HasScheduleOperations;

    public function __construct(
        protected ProjectScheduleRepositoryInterface $scheduleRepository,
        protected CriticalPathService $criticalPathService
    ) {
    }

    public function index(Request $request, int $project): JsonResponse
    {
        return $this->runAction('index', $request, function () use ($request, $project) {
            $schedules = $this->scheduleRepository->getPaginatedForProject(
                $project,
                min((int) $request->get('per_page', 15), 100),
                $request->only([
                    'status',
                    'is_template',
                    'search',
                    'date_from',
                    'date_to',
                    'critical_path_calculated',
                    'sort_by',
                    'sort_order',
                ])
            );

            $payload = (new ProjectScheduleCollection($schedules))->response()->getData(true);

            return AdminResponse::paginated(
                $payload['data'] ?? [],
                $payload['meta'] ?? [],
                null,
                Response::HTTP_OK,
                null,
                $payload['links'] ?? null
            );
        });
    }

    public function store(CreateProjectScheduleRequest $request, int $project): JsonResponse
    {
        return $this->runAction('store', $request, function () use ($request, $project) {
            $data = $request->validated();
            $data['project_id'] = $project;
            $data['organization_id'] = $this->getOrganizationId($request);
            $data['created_by_user_id'] = $request->user()->id;
            $data['status'] = $data['status'] ?? 'draft';
            $data['overall_progress_percent'] = 0;
            $data['critical_path_calculated'] = false;

            $schedule = $this->scheduleRepository->create($data);

            return AdminResponse::success(
                new ProjectScheduleResource($schedule->load(['project', 'createdBy'])),
                trans_message('schedule_management.schedule_created'),
                Response::HTTP_CREATED
            );
        });
    }

    public function show(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction('show', $request, function () use ($request, $project, $schedule) {
            $scheduleModel = $this->findProjectScheduleOrFail($project, $schedule);

            if ($request->get('format') === 'gantt') {
                $scheduleModel->load([
                    'rootTasks' => fn ($query) => $query->orderBy('sort_order')->withCount('completedWorks'),
                    'rootTasks.childTasks' => fn ($query) => $query->orderBy('sort_order')
                        ->with('intervals')
                        ->withCount('completedWorks'),
                    'rootTasks.intervals',
                    'rootTasks.predecessorDependencies',
                    'tasks' => fn ($query) => $query->orderBy('sort_order')
                        ->with('intervals')
                        ->withCount('completedWorks'),
                    'dependencies',
                ]);

                return AdminResponse::success(new ScheduleGanttResource($scheduleModel));
            }

            return AdminResponse::success(
                new ProjectScheduleResource(
                    $scheduleModel->load(['project', 'createdBy', 'tasks.intervals', 'dependencies', 'resources'])
                )
            );
        });
    }

    public function update(UpdateProjectScheduleRequest $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction('update', $request, function () use ($request, $project, $schedule) {
            $scheduleModel = $this->findProjectScheduleOrFail($project, $schedule);
            $data = $request->validated();

            unset($data['project_id'], $data['organization_id'], $data['created_by_user_id']);

            if (($data['is_template'] ?? false) === true) {
                unset($data['is_template']);
            }

            $this->scheduleRepository->update($scheduleModel->id, $data);
            $scheduleModel = $this->scheduleRepository->findForProject($scheduleModel->id, $project);

            return AdminResponse::success(
                new ProjectScheduleResource($scheduleModel->load(['project', 'createdBy'])),
                trans_message('schedule_management.schedule_updated')
            );
        });
    }

    public function destroy(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction('destroy', $request, function () use ($project, $schedule) {
            $scheduleModel = $this->findProjectScheduleOrFail($project, $schedule);
            $this->scheduleRepository->delete($scheduleModel->id);

            return AdminResponse::success(null, trans_message('schedule_management.schedule_deleted'));
        });
    }

    public function calculateCriticalPath(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'calculateCriticalPath',
            $request,
            fn () => $this->calculateCriticalPathForSchedule(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function saveBaseline(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'saveBaseline',
            $request,
            fn () => $this->saveBaselineForSchedule(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                $request,
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function clearBaseline(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'clearBaseline',
            $request,
            fn () => $this->clearBaselineForSchedule(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function tasks(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'tasks',
            $request,
            fn () => $this->getScheduleTasks(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function storeTask(CreateScheduleTaskRequest $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'storeTask',
            $request,
            fn () => $this->createScheduleTask(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                $request,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function dependencies(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'dependencies',
            $request,
            fn () => $this->getScheduleDependencies(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function storeDependency(CreateTaskDependencyRequest $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'storeDependency',
            $request,
            fn () => $this->createScheduleDependency(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                $request,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function updateDependency(
        UpdateTaskDependencyRequest $request,
        int $project,
        int $schedule,
        int $dependency
    ): JsonResponse {
        return $this->runAction(
            'updateDependency',
            $request,
            fn () => $this->updateScheduleDependency(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                $dependency,
                $request,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function destroyDependency(Request $request, int $project, int $schedule, int $dependency): JsonResponse
    {
        return $this->runAction(
            'destroyDependency',
            $request,
            fn () => $this->deleteScheduleDependency(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                $dependency,
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    public function export(Request $request, int $project, int $schedule): StreamedResponse
    {
        $scheduleModel = $this->findProjectScheduleOrFail($project, $schedule);
        $exportService = app(GanttExcelExportService::class);
        $filePath = $exportService->export($scheduleModel);
        $filename = 'График_' . preg_replace('/[^a-zA-Z0-9А-Яа-я_-]/u', '_', $scheduleModel->name)
            . '_' . now()->format('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($filePath) {
            readfile($filePath);
            @unlink($filePath);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function resourceConflicts(Request $request, int $project, int $schedule): JsonResponse
    {
        return $this->runAction(
            'resourceConflicts',
            $request,
            fn () => $this->getResourceConflicts(
                $this->findProjectScheduleOrFail($project, $schedule)->id,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForProject($scheduleId, $project)
            )
        );
    }

    private function getOrganizationId(Request $request): int
    {
        $organization = ProjectContextMiddleware::getOrganization($request);
        if ($organization) {
            return $organization->id;
        }

        $user = $request->user();
        $organizationId = $user->current_organization_id ?? $user->organization_id;
        if (!$organizationId) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, trans_message('schedule_management.organization_required'));
        }

        return (int) $organizationId;
    }

    private function findProjectScheduleOrFail(int $project, int $scheduleId): object
    {
        $schedule = $this->scheduleRepository->findForProject($scheduleId, $project);
        if (!$schedule) {
            throw new ScheduleNotFoundException($scheduleId);
        }

        if ($schedule->project_id !== $project) {
            throw new HttpException(Response::HTTP_NOT_FOUND, trans_message('schedule_management.schedule_not_found'));
        }

        return $schedule;
    }

    private function runAction(string $action, Request $request, callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            return AdminResponse::error(
                trans_message('schedule_management.validation_error'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors()
            );
        } catch (ScheduleNotFoundException) {
            return AdminResponse::error(trans_message('schedule_management.schedule_not_found'), Response::HTTP_NOT_FOUND);
        } catch (CircularDependencyException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, [
                'cycle_tasks' => $e->getCycleTasks(),
            ]);
        } catch (HttpExceptionInterface $e) {
            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : $this->resolveErrorMessage($action),
                $e->getStatusCode()
            );
        } catch (\Throwable $e) {
            Log::error("[AdminProjectScheduleController.{$action}] Unexpected error", [
                'message' => $e->getMessage(),
                'project_id' => $request->route('project'),
                'user_id' => $request->user()?->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error($this->resolveErrorMessage($action), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveErrorMessage(string $action): string
    {
        return match ($action) {
            'store' => trans_message('schedule_management.schedule_create_error'),
            'show', 'tasks', 'dependencies' => trans_message('schedule_management.schedule_details_load_error'),
            'update' => trans_message('schedule_management.schedule_update_error'),
            'destroy' => trans_message('schedule_management.schedule_delete_error'),
            'storeTask' => trans_message('schedule_management.task_create_error'),
            'storeDependency' => trans_message('schedule_management.dependency_create_error'),
            'updateDependency' => trans_message('schedule_management.dependency_update_error'),
            'destroyDependency' => trans_message('schedule_management.dependency_delete_error'),
            'resourceConflicts' => trans_message('schedule_management.resource_conflicts_details_error'),
            default => trans_message('schedule_management.schedule_load_error'),
        };
    }
}
