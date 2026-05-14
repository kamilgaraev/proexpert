<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileProjectScheduleService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly MobileProjectScheduleService $scheduleService
    ) {
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

    public function recordAssignmentFact(int $assignment, Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            $validated = $request->validate([
                'status' => ['required', 'string', Rule::in(['done', 'partially_done', 'not_done'])],
                'completed_quantity' => ['nullable', 'numeric', 'min:0'],
                'actual_work_hours' => ['nullable', 'numeric', 'min:0'],
                'fact_comment' => ['nullable', 'string', 'max:2000'],
                'failure_reason' => ['nullable', 'required_if:status,not_done', 'string', 'max:2000'],
            ]);

            return MobileResponse::success($this->scheduleService->recordDailyPlanFact($user, $assignment, $validated));
        } catch (ValidationException $exception) {
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
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

    public function submitDailyPlan(int $dailyPlan, Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            $validated = $request->validate([
                'summary_comment' => ['nullable', 'string', 'max:1000'],
            ]);

            return MobileResponse::success($this->scheduleService->submitDailyPlan($user, $dailyPlan, $validated));
        } catch (ValidationException $exception) {
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
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

    public function createLinkedConstraintAction(int $constraint, Request $request): JsonResponse
    {
        try {
            /** @var \App\Models\User|null $user */
            $user = $request->user();

            if (!$user) {
                return MobileResponse::error(trans_message('mobile_schedule.errors.unauthorized'), 401);
            }

            $validated = $request->validate([
                'comment' => ['nullable', 'string', 'max:1000'],
            ]);

            $action = $this->scheduleService->createLinkedActionForConstraint($user, $constraint, $validated);

            return MobileResponse::success(
                $action,
                trans_message('mobile_schedule.messages.constraint_linked_action_created'),
                $action['created'] ? 201 : 200
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error($exception->getMessage(), 422, $exception->errors());
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
