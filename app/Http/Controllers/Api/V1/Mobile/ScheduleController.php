<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\StoreMobileScheduleTaskRequest;
use App\Http\Requests\Api\V1\Mobile\UpdateMobileScheduleTaskRequest;
use App\Http\Requests\Api\V1\Mobile\MobileScheduleMutationRequest;
use App\Http\Resources\Api\V1\Schedule\ScheduleTaskResource;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileScheduleTaskService;
use App\Services\Mobile\MobileProjectScheduleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly MobileProjectScheduleService $scheduleService,
        private readonly MobileScheduleTaskService $scheduleTaskService,
    ) {
    }

    public function storeTask(StoreMobileScheduleTaskRequest $request, int $schedule_id): JsonResponse
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }
            $task = $this->scheduleTaskService->create($actor, $schedule_id, $request->validated());

            return MobileResponse::success(new ScheduleTaskResource($task), trans_message('schedule_management.task_created'), 201);
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.task.create.error', [
                'user_id' => $request->user()?->id,
                'schedule_id' => $schedule_id,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('schedule_management.task_create_error'), 500);
        }
    }

    public function updateTask(UpdateMobileScheduleTaskRequest $request, int $task): JsonResponse
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }
            $result = $this->scheduleTaskService->updateById($actor, $task, $request->validated());

            return MobileResponse::success([
                'task' => new ScheduleTaskResource($result['task']),
                'affected_tasks' => ScheduleTaskResource::collection($result['affected_tasks']),
            ], trans_message('schedule_management.task_updated'));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.task.update.error', [
                'user_id' => $request->user()?->id,
                'task_id' => $task,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('schedule_management.task_update_error'), 500);
        }
    }

    public function showTask(Request $request, int $task): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
        }

        try {
            return MobileResponse::success($this->scheduleTaskService->show($actor, $task));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.task.show.error', [
                'user_id' => $actor->id,
                'task_id' => $task,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.load_failed'), 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            $projectId = $request->integer('project_id');

            return MobileResponse::success($this->scheduleService->list($user, $projectId));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.index.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'project_id' => $request->input('project_id'),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.load_failed'), 500);
        }
    }

    public function show(int $scheduleId, Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->scheduleService->show($user, $scheduleId));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.show.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'schedule_id' => $scheduleId,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.load_failed'), 500);
        }
    }

    public function dailyPlans(Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->scheduleService->dailyPlans($user, $request->integer('project_id')));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.daily_plans.error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'project_id' => $request->input('project_id'),
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.load_failed'), 500);
        }
    }

    public function recordAssignmentFact(int $assignment, MobileScheduleMutationRequest $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->scheduleService->recordDailyPlanFact($user, $assignment, $request->validated()));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.record_assignment_fact.error', [
                'user_id' => $request->user()?->id,
                'assignment_id' => $assignment,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.fact_failed'), 500);
        }
    }

    public function submitDailyPlan(int $dailyPlan, MobileScheduleMutationRequest $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            return MobileResponse::success($this->scheduleService->submitDailyPlan($user, $dailyPlan, $request->validated()));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 400);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.submit_daily_plan.error', [
                'user_id' => $request->user()?->id,
                'daily_plan_id' => $dailyPlan,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.submit_failed'), 500);
        }
    }

    public function createLinkedConstraintAction(int $constraint, MobileScheduleMutationRequest $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            $action = $this->scheduleService->createLinkedActionForConstraint($user, $constraint, $request->validated());

            return MobileResponse::success(
                $action,
                trans_message('mobile_schedule.messages.constraint_linked_action_created'),
                $action['created'] ? 201 : 200
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            Log::error('mobile.schedule.create_linked_constraint_action.error', [
                'user_id' => $request->user()?->id,
                'constraint_id' => $constraint,
                'error' => $exception->getMessage(),
            ]);

            return MobileResponse::error(trans_message('mobile_schedule.errors.constraint_linked_action_failed'), 500);
        }
    }
}
