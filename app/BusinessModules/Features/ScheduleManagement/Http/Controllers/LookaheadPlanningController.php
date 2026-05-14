<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Http\Controllers;

use App\BusinessModules\Features\ScheduleManagement\Http\Resources\DailyWorkPlanResource;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\DailyWorkPlanAssignmentResource;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\LookaheadPlanResource;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\LookaheadPlanTaskResource;
use App\BusinessModules\Features\ScheduleManagement\Http\Resources\WorkConstraintResource;
use App\BusinessModules\Features\ScheduleManagement\Services\LookaheadPlanningService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\ProjectSchedule;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class LookaheadPlanningController extends Controller
{
    public function __construct(
        private readonly LookaheadPlanningService $service,
    ) {
    }

    public function indexPlans(int $project, int $schedule): JsonResponse
    {
        try {
            return AdminResponse::success(
                LookaheadPlanResource::collection($this->service->listPlans($this->findSchedule($project, $schedule)))
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->failed('index_plans', $e, compact('project', 'schedule'));
        }
    }

    public function storePlan(Request $request, int $project, int $schedule): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => ['nullable', 'string', 'max:255'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            ]);

            return AdminResponse::success(
                new LookaheadPlanResource($this->service->createPlan($this->findSchedule($project, $schedule), (int) $request->user()->id, $validated)),
                trans_message('schedule_management.lookahead_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('store_plan', $e, ['project_id' => $project, 'schedule_id' => $schedule]);
        }
    }

    public function storePlanTask(Request $request, int $project, int $schedule, int $plan): JsonResponse
    {
        try {
            $validated = $request->validate([
                'schedule_task_id' => ['required', 'integer'],
                'planned_start_date' => ['required', 'date'],
                'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
                'planned_quantity' => ['nullable', 'numeric', 'min:0'],
                'planned_work_hours' => ['nullable', 'numeric', 'min:0'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new LookaheadPlanTaskResource($this->service->addTask($scheduleModel, $this->service->findLookaheadPlan($scheduleModel, $plan), $validated)),
                trans_message('schedule_management.lookahead_task_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('store_plan_task', $e, compact('project', 'schedule', 'plan'));
        }
    }

    public function storeConstraint(Request $request, int $project, int $schedule, int $planTask): JsonResponse
    {
        try {
            $validated = $request->validate([
                'constraint_type' => ['required', 'string', Rule::in([
                    'material_missing',
                    'labor_missing',
                    'machinery_missing',
                    'design_question',
                    'executive_doc_missing',
                    'safety_permit_missing',
                    'quality_blocker',
                    'access_blocked',
                    'weather_risk',
                    'customer_decision',
                    'other',
                ])],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'severity' => ['nullable', 'string', Rule::in(['soft', 'hard'])],
                'due_date' => ['nullable', 'date'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new WorkConstraintResource($this->service->addConstraint(
                    $scheduleModel,
                    $this->service->findLookaheadTask($scheduleModel, $planTask),
                    (int) $request->user()->id,
                    $validated
                )),
                trans_message('schedule_management.constraint_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('store_constraint', $e, compact('project', 'schedule', 'planTask'));
        }
    }

    public function storeDailyPlan(Request $request, int $project, int $schedule): JsonResponse
    {
        try {
            $validated = $request->validate([
                'lookahead_plan_id' => ['required', 'integer'],
                'work_date' => ['required', 'date'],
                'summary_comment' => ['nullable', 'string', 'max:1000'],
                'assignments' => ['required', 'array', 'min:1'],
                'assignments.*.lookahead_plan_task_id' => ['required', 'integer'],
                'assignments.*.assigned_user_id' => ['nullable', 'integer'],
                'assignments.*.planned_quantity' => ['nullable', 'numeric', 'min:0'],
                'assignments.*.planned_work_hours' => ['nullable', 'numeric', 'min:0'],
            ]);

            return AdminResponse::success(
                new DailyWorkPlanResource($this->service->createDailyPlan($this->findSchedule($project, $schedule), (int) $request->user()->id, $validated)),
                trans_message('schedule_management.daily_plan_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('store_daily_plan', $e, compact('project', 'schedule'));
        }
    }

    public function publishDailyPlan(Request $request, int $project, int $schedule, int $dailyPlan): JsonResponse
    {
        try {
            $validated = $request->validate([
                'override_constraint_ids' => ['nullable', 'array'],
                'override_constraint_ids.*' => ['integer'],
                'override_reason' => ['required_with:override_constraint_ids', 'string', 'max:1000'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(new DailyWorkPlanResource($this->service->publishDailyPlan(
                $scheduleModel,
                $this->service->findDailyPlan($scheduleModel, $dailyPlan),
                (int) $request->user()->id,
                $validated
            )));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            $scheduleModel = ProjectSchedule::query()->find($schedule);
            $errors = [];

            if ($scheduleModel) {
                $daily = $this->service->findDailyPlan($scheduleModel, $dailyPlan);
                $errors['constraints'] = WorkConstraintResource::collection(
                    $this->service->openHardConstraintsForDailyPlan($daily)
                )->resolve();
            }

            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $errors ?: null);
        } catch (\Throwable $e) {
            return $this->failed('publish_daily_plan', $e, compact('project', 'schedule', 'dailyPlan'));
        }
    }

    public function recordAssignmentFact(Request $request, int $project, int $schedule, int $assignment): JsonResponse
    {
        try {
            $validated = $request->validate([
                'status' => ['required', 'string', Rule::in(['done', 'partially_done', 'not_done'])],
                'completed_quantity' => ['nullable', 'numeric', 'min:0'],
                'actual_work_hours' => ['nullable', 'numeric', 'min:0'],
                'fact_comment' => ['nullable', 'string', 'max:2000'],
                'failure_reason' => ['nullable', 'required_if:status,not_done', 'string', 'max:2000'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new DailyWorkPlanAssignmentResource($this->service->recordAssignmentFact(
                    $scheduleModel,
                    $this->service->findAssignment($scheduleModel, $assignment),
                    (int) $request->user()->id,
                    $validated
                )),
                trans_message('schedule_management.daily_plan_fact_recorded')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('record_assignment_fact', $e, compact('project', 'schedule', 'assignment'));
        }
    }

    public function submitDailyPlan(Request $request, int $project, int $schedule, int $dailyPlan): JsonResponse
    {
        try {
            $validated = $request->validate([
                'summary_comment' => ['nullable', 'string', 'max:1000'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new DailyWorkPlanResource($this->service->submitDailyPlan(
                    $scheduleModel,
                    $this->service->findDailyPlan($scheduleModel, $dailyPlan),
                    $validated
                )),
                trans_message('schedule_management.daily_plan_submitted')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('submit_daily_plan', $e, compact('project', 'schedule', 'dailyPlan'));
        }
    }

    public function acceptDailyPlan(Request $request, int $project, int $schedule, int $dailyPlan): JsonResponse
    {
        try {
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new DailyWorkPlanResource($this->service->acceptDailyPlan(
                    $scheduleModel,
                    $this->service->findDailyPlan($scheduleModel, $dailyPlan),
                    (int) $request->user()->id
                )),
                trans_message('schedule_management.daily_plan_accepted')
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('accept_daily_plan', $e, compact('project', 'schedule', 'dailyPlan'));
        }
    }

    public function returnDailyPlan(Request $request, int $project, int $schedule, int $dailyPlan): JsonResponse
    {
        try {
            $validated = $request->validate([
                'return_reason' => ['required', 'string', 'max:2000'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new DailyWorkPlanResource($this->service->returnDailyPlan(
                    $scheduleModel,
                    $this->service->findDailyPlan($scheduleModel, $dailyPlan),
                    (int) $request->user()->id,
                    $validated
                )),
                trans_message('schedule_management.daily_plan_returned')
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('return_daily_plan', $e, compact('project', 'schedule', 'dailyPlan'));
        }
    }

    public function closeDailyPlan(Request $request, int $project, int $schedule, int $dailyPlan): JsonResponse
    {
        try {
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new DailyWorkPlanResource($this->service->closeDailyPlan(
                    $scheduleModel,
                    $this->service->findDailyPlan($scheduleModel, $dailyPlan),
                    (int) $request->user()->id
                )),
                trans_message('schedule_management.daily_plan_closed')
            );
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('close_daily_plan', $e, compact('project', 'schedule', 'dailyPlan'));
        }
    }

    public function reviseDailyPlan(Request $request, int $project, int $schedule, int $dailyPlan): JsonResponse
    {
        try {
            $validated = $request->validate([
                'revision_reason' => ['required', 'string', 'max:2000'],
            ]);
            $scheduleModel = $this->findSchedule($project, $schedule);

            return AdminResponse::success(
                new DailyWorkPlanResource($this->service->reviseDailyPlan(
                    $scheduleModel,
                    $this->service->findDailyPlan($scheduleModel, $dailyPlan),
                    (int) $request->user()->id,
                    $validated
                )),
                trans_message('schedule_management.daily_plan_revision_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return $this->failed('revise_daily_plan', $e, compact('project', 'schedule', 'dailyPlan'));
        }
    }

    private function findSchedule(int $project, int $schedule): ProjectSchedule
    {
        $scheduleModel = ProjectSchedule::query()
            ->where('project_id', $project)
            ->find($schedule);

        if (!$scheduleModel) {
            throw new DomainException(trans_message('schedule_management.schedule_not_found'));
        }

        return $scheduleModel;
    }

    private function failed(string $action, \Throwable $e, array $context): JsonResponse
    {
        Log::error("schedule_management.lookahead.{$action}.error", $context + [
            'error' => $e->getMessage(),
            'user_id' => auth()->id(),
        ]);

        return AdminResponse::error(trans_message('schedule_management.lookahead_action_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
