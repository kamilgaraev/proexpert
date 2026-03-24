<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\CompletedWork;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function trans_message;

class OnboardingDemoController extends Controller
{
    public function deleteDemoData(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            return AdminResponse::error(
                trans_message('onboarding.organization_not_found'),
                Response::HTTP_BAD_REQUEST
            );
        }

        try {
            $deleted = DB::transaction(function () use ($organizationId) {
                $counts = [
                    'projects' => 0,
                    'contracts' => 0,
                    'contractors' => 0,
                    'estimates' => 0,
                    'schedules' => 0,
                    'schedule_tasks' => 0,
                    'events' => 0,
                    'materials' => 0,
                    'completed_works' => 0,
                ];

                $counts['completed_works'] = CompletedWork::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['schedule_tasks'] = ScheduleTask::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['events'] = ProjectEvent::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['schedules'] = ProjectSchedule::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['estimates'] = Estimate::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['contracts'] = Contract::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['materials'] = Material::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                $counts['contractors'] = Organization::where('id', '!=', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->whereHas('participantProjects', function ($query) use ($organizationId) {
                        $query->where('projects.organization_id', $organizationId);
                    })
                    ->delete();

                $counts['projects'] = Project::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                return $counts;
            });

            $totalDeleted = array_sum($deleted);

            Log::info('onboarding.demo_data_deleted', [
                'organization_id' => $organizationId,
                'counts' => $deleted,
                'total' => $totalDeleted,
            ]);

            return AdminResponse::success(
                [
                    'deleted' => $deleted,
                    'total_deleted' => $totalDeleted,
                ],
                trans_message('onboarding.demo_data_deleted', ['total' => $totalDeleted])
            );
        } catch (Throwable $e) {
            Log::error('onboarding.delete_demo_data.error', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(
                trans_message('onboarding.demo_data_delete_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
