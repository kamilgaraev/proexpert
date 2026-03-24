<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Schedule;

use App\Exceptions\Schedule\CircularDependencyException;
use App\Exceptions\Schedule\ResourceConflictException;
use App\Exceptions\Schedule\ScheduleNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasScheduleOperations;
use App\Http\Requests\Api\V1\Schedule\CreateProjectScheduleRequest;
use App\Http\Requests\Api\V1\Schedule\CreateScheduleTaskRequest;
use App\Http\Requests\Api\V1\Schedule\CreateTaskDependencyRequest;
use App\Http\Requests\Api\V1\Schedule\UpdateProjectScheduleRequest;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleCollection;
use App\Http\Resources\Api\V1\Schedule\ProjectScheduleResource;
use App\Http\Resources\Api\V1\Schedule\ScheduleGanttResource;
use App\Http\Responses\AdminResponse;
use App\Repositories\Interfaces\ProjectScheduleRepositoryInterface;
use App\Services\Schedule\CriticalPathService;
use App\Services\Schedule\ScheduleTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use function trans_message;

class ProjectScheduleController extends Controller
{
    use HasScheduleOperations;

    public function __construct(
        protected ProjectScheduleRepositoryInterface $scheduleRepository,
        protected CriticalPathService $criticalPathService,
        protected ScheduleTaskService $taskService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->runAction('index', $request, function () use ($request) {
            $schedules = $this->scheduleRepository->getPaginatedForOrganization(
                $this->getOrganizationId($request),
                min((int) $request->get('per_page', 15), 100),
                $request->only([
                    'status',
                    'project_id',
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

    public function store(CreateProjectScheduleRequest $request): JsonResponse
    {
        return $this->runAction(
            'store',
            $request,
            fn () => $this->createSchedule($request, $this->getOrganizationId($request))
        );
    }

    public function show(string $id, Request $request): JsonResponse
    {
        return $this->runAction('show', $request, function () use ($id, $request) {
            $schedule = $this->scheduleRepository->findForOrganization((int) $id, $this->getOrganizationId($request));

            if (!$schedule) {
                throw new ScheduleNotFoundException((int) $id);
            }

            if ($request->get('format') === 'gantt') {
                if ($schedule->tasks()->where('sort_order', 0)->exists()) {
                    $this->taskService->reorderTasks($schedule);
                    $schedule = $this->scheduleRepository->findForOrganization((int) $id, $this->getOrganizationId($request));
                }

                $schedule->load([
                    'rootTasks.childTasks' => fn ($query) => $query->orderBy('sort_order'),
                    'rootTasks.predecessorDependencies',
                    'tasks' => fn ($query) => $query->orderBy('sort_order'),
                    'dependencies',
                ]);

                return AdminResponse::success(new ScheduleGanttResource($schedule));
            }

            return AdminResponse::success(
                new ProjectScheduleResource(
                    $schedule->load(['project', 'createdBy', 'tasks', 'dependencies', 'resources'])
                )
            );
        });
    }

    public function update(string $id, UpdateProjectScheduleRequest $request): JsonResponse
    {
        return $this->runAction(
            'update',
            $request,
            fn () => $this->updateSchedule(
                (int) $id,
                $request,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function destroy(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'destroy',
            $request,
            fn () => $this->deleteSchedule(
                (int) $id,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function calculateCriticalPath(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'calculateCriticalPath',
            $request,
            fn () => $this->calculateCriticalPathForSchedule(
                (int) $id,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function saveBaseline(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'saveBaseline',
            $request,
            fn () => $this->saveBaselineForSchedule(
                (int) $id,
                $request,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function clearBaseline(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'clearBaseline',
            $request,
            fn () => $this->clearBaselineForSchedule(
                (int) $id,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function templates(Request $request): JsonResponse
    {
        return $this->runAction('templates', $request, function () use ($request) {
            return AdminResponse::success(
                ProjectScheduleResource::collection(
                    $this->scheduleRepository->getTemplatesForOrganization($this->getOrganizationId($request))
                )
            );
        });
    }

    public function createFromTemplate(Request $request): JsonResponse
    {
        return $this->runAction('createFromTemplate', $request, function () use ($request) {
            $validated = $request->validate([
                'template_id' => 'required|integer|exists:project_schedules,id',
                'project_id' => 'required|integer|exists:projects,id',
                'name' => 'required|string|max:255',
                'planned_start_date' => 'required|date',
                'planned_end_date' => 'required|date|after:planned_start_date',
            ]);

            $schedule = $this->scheduleRepository->createFromTemplate(
                (int) $validated['template_id'],
                (int) $validated['project_id'],
                [
                    'name' => $validated['name'],
                    'planned_start_date' => $validated['planned_start_date'],
                    'planned_end_date' => $validated['planned_end_date'],
                    'organization_id' => $this->getOrganizationId($request),
                    'created_by_user_id' => $request->user()->id,
                ]
            );

            return AdminResponse::success(
                new ProjectScheduleResource($schedule->load(['project', 'createdBy'])),
                trans_message('schedule_management.schedule_from_template_created'),
                Response::HTTP_CREATED
            );
        });
    }

    public function statistics(Request $request): JsonResponse
    {
        return $this->runAction('statistics', $request, function () use ($request) {
            return AdminResponse::success(
                $this->scheduleRepository->getOrganizationStats($this->getOrganizationId($request))
            );
        });
    }

    public function overdue(Request $request): JsonResponse
    {
        return $this->runAction('overdue', $request, function () use ($request) {
            return AdminResponse::success(
                ProjectScheduleResource::collection(
                    $this->scheduleRepository->getWithOverdueTasks($this->getOrganizationId($request))
                )
            );
        });
    }

    public function recent(Request $request): JsonResponse
    {
        return $this->runAction('recent', $request, function () use ($request) {
            return AdminResponse::success(
                ProjectScheduleResource::collection(
                    $this->scheduleRepository->getRecentlyUpdated(
                        $this->getOrganizationId($request),
                        (int) $request->get('limit', 10)
                    )
                )
            );
        });
    }

    public function allResourceConflicts(Request $request): JsonResponse
    {
        return $this->runAction('allResourceConflicts', $request, function () use ($request) {
            $conflictedSchedules = $this->scheduleRepository->getWithResourceConflicts($this->getOrganizationId($request));

            return AdminResponse::success([
                'schedules' => ProjectScheduleResource::collection($conflictedSchedules)->resolve(),
                'total_schedules_with_conflicts' => $conflictedSchedules->count(),
                'has_conflicts' => $conflictedSchedules->isNotEmpty(),
            ]);
        });
    }

    public function tasks(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'tasks',
            $request,
            fn () => $this->getScheduleTasks(
                (int) $id,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function dependencies(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'dependencies',
            $request,
            fn () => $this->getScheduleDependencies(
                (int) $id,
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function storeDependency(string $id, CreateTaskDependencyRequest $request): JsonResponse
    {
        return $this->runAction(
            'storeDependency',
            $request,
            fn () => $this->createScheduleDependency(
                (int) $id,
                $request,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function resourceConflicts(string $id, Request $request): JsonResponse
    {
        return $this->runAction(
            'resourceConflicts',
            $request,
            fn () => $this->getResourceConflicts(
                (int) $id,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    public function storeTask(string $id, CreateScheduleTaskRequest $request): JsonResponse
    {
        return $this->runAction(
            'storeTask',
            $request,
            fn () => $this->createScheduleTask(
                (int) $id,
                $request,
                $this->getOrganizationId($request),
                fn (int $scheduleId) => $this->scheduleRepository->findForOrganization(
                    $scheduleId,
                    $this->getOrganizationId($request)
                )
            )
        );
    }

    private function getOrganizationId(Request $request): int
    {
        $user = $request->user();
        $organizationId = $user->current_organization_id ?? $user->organization_id;

        if (!$organizationId) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, trans_message('schedule_management.organization_required'));
        }

        return (int) $organizationId;
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
        } catch (ResourceConflictException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HttpExceptionInterface $e) {
            return AdminResponse::error(
                $e->getMessage() !== '' ? $e->getMessage() : $this->resolveErrorMessage($action),
                $e->getStatusCode()
            );
        } catch (\Throwable $e) {
            Log::error("[ProjectScheduleController.{$action}] Unexpected error", [
                'message' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id ?? $request->user()?->organization_id,
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
            'templates' => trans_message('schedule_management.schedule_templates_load_error'),
            'createFromTemplate' => trans_message('schedule_management.schedule_from_template_error'),
            'statistics' => trans_message('schedule_management.statistics_load_error'),
            'overdue' => trans_message('schedule_management.overdue_load_error'),
            'recent' => trans_message('schedule_management.recent_load_error'),
            'allResourceConflicts' => trans_message('schedule_management.resource_conflicts_load_error'),
            'resourceConflicts' => trans_message('schedule_management.resource_conflicts_details_error'),
            'storeDependency' => trans_message('schedule_management.dependency_create_error'),
            'storeTask' => trans_message('schedule_management.task_create_error'),
            default => trans_message('schedule_management.schedule_load_error'),
        };
    }
}
