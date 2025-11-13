<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Project;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Estimate;
use App\Models\ProjectSchedule;
use App\Models\ScheduleTask;
use App\BusinessModules\Features\ScheduleManagement\Models\ProjectEvent;
use App\Models\Material;
use App\Models\CompletedWork;
use Symfony\Component\HttpFoundation\Response;

class OnboardingDemoController extends Controller
{
    /**
     * Удалить все демо-данные текущей организации
     * 
     * @group Onboarding
     * @authenticated
     */
    public function deleteDemoData(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            if (!$organizationId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Организация не найдена',
                ], Response::HTTP_BAD_REQUEST);
            }

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

                // Удаляем completed_works (зависимые от contracts)
                $counts['completed_works'] = CompletedWork::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем schedule_tasks (зависимые от schedules)
                $counts['schedule_tasks'] = ScheduleTask::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем events (зависимые от schedules/projects)
                $counts['events'] = ProjectEvent::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем schedules (зависимые от projects)
                $counts['schedules'] = ProjectSchedule::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем estimates (зависимые от projects)
                $counts['estimates'] = Estimate::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем contracts (зависимые от projects)
                $counts['contracts'] = Contract::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем materials
                $counts['materials'] = Material::where('organization_id', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->delete();

                // Удаляем contractors (organizations с флагом demo)
                $counts['contractors'] = Organization::where('id', '!=', $organizationId)
                    ->where('is_onboarding_demo', true)
                    ->whereHas('projectOrganizations', function ($query) use ($organizationId) {
                        // Только те подрядчики, которые связаны с проектами этой организации
                        $query->whereHas('project', function ($projectQuery) use ($organizationId) {
                            $projectQuery->where('organization_id', $organizationId);
                        });
                    })
                    ->delete();

                // Удаляем projects (каскадно удалятся связанные данные)
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

            return response()->json([
                'success' => true,
                'deleted' => $deleted,
                'message' => "Удалено {$totalDeleted} демо-сущностей",
            ]);

        } catch (\Exception $e) {
            Log::error('onboarding.delete_demo_data.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Не удалось удалить демо-данные',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
