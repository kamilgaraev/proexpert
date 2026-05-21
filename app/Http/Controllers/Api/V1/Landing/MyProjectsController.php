<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Enums\Schedule\TaskStatusEnum;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectSchedule;
use App\Services\Project\ProjectContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

class MyProjectsController extends Controller
{
    public function __construct(
        protected ProjectContextService $projectContextService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;

            if (!$organization) {
                Log::warning('User has no current organization', [
                    'user_id' => $user->id,
                    'current_organization_id' => $user->current_organization_id,
                ]);

                return LandingResponse::error(trans_message('landing.organization_not_found'), 404);
            }

            $projects = $this->projectContextService->getAccessibleProjects($organization);

            $projectsData = array_map(function ($projectData) use ($organization) {
                $project = $projectData['project'];
                $role = $projectData['role'];
                $isOwner = $projectData['is_owner'];
                $stats = $this->getProjectStats($project, $organization);
                $schedule = ProjectSchedule::where('project_id', $project->id)->first();
                $progress = $schedule ? $schedule->overall_progress_percent : 0;

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'address' => $project->address,
                    'status' => $project->status,
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'budget_amount' => $project->budget_amount,
                    'is_archived' => $project->is_archived,
                    'role' => [
                        'value' => $role->value,
                        'label' => $role->label(),
                    ],
                    'is_owner' => $isOwner,
                    'stats' => $stats,
                    'progress_percent' => $progress,
                ];
            }, $projects);

            $groupedProjects = [
                'owned' => array_filter($projectsData, fn ($project) => $project['is_owner']),
                'participant' => array_filter($projectsData, fn ($project) => !$project['is_owner']),
            ];

            return LandingResponse::success([
                'projects' => array_values($projectsData),
                'grouped' => [
                    'owned' => array_values($groupedProjects['owned']),
                    'participant' => array_values($groupedProjects['participant']),
                ],
                'totals' => [
                    'all' => count($projectsData),
                    'owned' => count($groupedProjects['owned']),
                    'participant' => count($groupedProjects['participant']),
                    'active' => count(array_filter($projectsData, fn ($project) => !$project['is_archived'])),
                    'archived' => count(array_filter($projectsData, fn ($project) => $project['is_archived'])),
                ],
            ], trans_message('landing.projects.loaded'));
        } catch (\Throwable $e) {
            Log::error('Failed to get my projects', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing.projects.list_error'), 500);
        }
    }

    public function show(Request $request, int $project): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;

            if (!$organization) {
                return LandingResponse::error(trans_message('landing.organization_not_found'), 404);
            }

            $projectModel = Project::find($project);

            if (!$projectModel) {
                return LandingResponse::error(trans_message('landing.projects.project_not_found'), 404);
            }

            if (!$this->projectContextService->canOrganizationAccessProject($projectModel, $organization)) {
                return LandingResponse::error(trans_message('landing.projects.access_denied'), 403);
            }

            $role = $this->projectContextService->getOrganizationRole($projectModel, $organization);
            $stats = $this->getProjectStats($projectModel, $organization);
            $schedule = ProjectSchedule::where('project_id', $projectModel->id)->first();
            $tasksStats = ['open' => 0, 'overdue' => 0, 'completed' => 0, 'total' => 0];
            $nextMilestone = null;

            if ($schedule) {
                $tasksQuery = $schedule->tasks()->where('task_type', '!=', 'project');

                $tasksStats['total'] = (clone $tasksQuery)->count();
                $tasksStats['completed'] = (clone $tasksQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
                $tasksStats['open'] = (clone $tasksQuery)
                    ->whereIn('status', [TaskStatusEnum::NOT_STARTED, TaskStatusEnum::IN_PROGRESS])
                    ->count();
                $tasksStats['overdue'] = (clone $tasksQuery)
                    ->where('planned_end_date', '<', now())
                    ->where('progress_percent', '<', 100)
                    ->whereNotIn('status', [TaskStatusEnum::COMPLETED, TaskStatusEnum::CANCELLED])
                    ->count();

                $nextMilestone = $schedule->milestones()
                    ->where('target_date', '>=', now())
                    ->orderBy('target_date')
                    ->first();
            }

            $allParticipants = $this->projectContextService->getAllProjectParticipants($projectModel);
            $participantsCount = count($allParticipants);
            $keyParticipants = array_slice($allParticipants, 0, 5);
            $formattedParticipants = array_map(function ($participant) {
                return [
                    'id' => $participant['organization']->id,
                    'name' => $participant['organization']->name,
                    'role' => [
                        'value' => $participant['role']->value,
                        'label' => $participant['role']->label(),
                    ],
                    'logo' => $participant['organization']->logo_path,
                ];
            }, $keyParticipants);

            return LandingResponse::success([
                'project' => [
                    'id' => $projectModel->id,
                    'name' => $projectModel->name,
                    'address' => $projectModel->address,
                    'description' => $projectModel->description,
                    'customer' => $projectModel->customer,
                    'designer' => $projectModel->designer,
                    'status' => $projectModel->status,
                    'start_date' => $projectModel->start_date?->format('Y-m-d'),
                    'end_date' => $projectModel->end_date?->format('Y-m-d'),
                    'budget_amount' => $projectModel->budget_amount,
                    'site_area_m2' => $projectModel->site_area_m2,
                    'is_archived' => $projectModel->is_archived,
                    'contract_number' => $projectModel->contract_number,
                    'contract_date' => $projectModel->contract_date,
                ],
                'role' => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ],
                'is_owner' => $projectModel->organization_id === $organization->id,
                'stats' => $stats,
                'progress' => [
                    'percent' => $schedule ? $schedule->overall_progress_percent : 0,
                    'health' => $schedule ? $schedule->health_status : 'unknown',
                    'next_milestone' => $nextMilestone ? [
                        'name' => $nextMilestone->name,
                        'date' => $nextMilestone->target_date->format('Y-m-d'),
                    ] : null,
                ],
                'tasks_summary' => $tasksStats,
                'participants' => [
                    'total' => $participantsCount,
                    'list' => $formattedParticipants,
                ],
            ], trans_message('landing.projects.details_loaded'));
        } catch (\Throwable $e) {
            Log::error('Failed to get project details', [
                'project_id' => $project,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing.projects.details_error'), 500);
        }
    }

    private function getProjectStats(Project $project, Organization $currentOrganization): array
    {
        try {
            $projectId = $project->id;
            $organizationId = $currentOrganization->id;
            $projectOwnerId = $project->organization_id;
            $myContractorIds = [];

            if ($currentOrganization->tax_number) {
                $myContractorIds = Contractor::where('organization_id', $projectOwnerId)
                    ->where('inn', $currentOrganization->tax_number)
                    ->pluck('id')
                    ->toArray();
            }

            $contractsQuery = DB::table('contracts')->where('project_id', $projectId);
            $totalContracts = (clone $contractsQuery)->count();
            $totalAmount = (clone $contractsQuery)->sum('total_amount');
            $myContracts = 0;
            $myContractsAmount = 0;

            if (!empty($myContractorIds)) {
                $myContractsQuery = (clone $contractsQuery)->whereIn('contractor_id', $myContractorIds);
                $myContracts = $myContractsQuery->count();
                $myContractsAmount = $myContractsQuery->sum('total_amount');
            }

            $worksQuery = DB::table('completed_works')->where('project_id', $projectId);
            $totalWorks = (clone $worksQuery)->count();
            $totalWorksAmount = (clone $worksQuery)->sum('total_amount');
            $myWorks = 0;
            $myWorksAmount = 0;

            if (!empty($myContractorIds)) {
                $myWorksQuery = (clone $worksQuery)->whereIn('contractor_id', $myContractorIds);
                $myWorks = $myWorksQuery->count();
                $myWorksAmount = $myWorksQuery->sum('total_amount');
            }

            return [
                'contracts' => [
                    'total' => $totalContracts,
                    'my' => $myContracts,
                    'total_amount' => (float) $totalAmount,
                    'my_amount' => (float) $myContractsAmount,
                ],
                'works' => [
                    'total' => $totalWorks,
                    'my' => $myWorks,
                    'total_amount' => (float) $totalWorksAmount,
                    'my_amount' => (float) $myWorksAmount,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to get project stats', [
                'project_id' => $project->id,
                'organization_id' => $currentOrganization->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'contracts' => ['total' => 0, 'my' => 0, 'total_amount' => 0, 'my_amount' => 0],
                'works' => ['total' => 0, 'my' => 0, 'total_amount' => 0, 'my_amount' => 0],
            ];
        }
    }
}
