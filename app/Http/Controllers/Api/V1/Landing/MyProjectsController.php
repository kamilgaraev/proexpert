<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\ProjectContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ProjectSchedule;
use App\Models\Contractor;
use App\Enums\Schedule\TaskStatusEnum;
use App\Models\Project;
use App\Models\Organization;

class MyProjectsController extends Controller
{
    protected ProjectContextService $projectContextService;

    public function __construct(ProjectContextService $projectContextService)
    {
        $this->projectContextService = $projectContextService;
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРїРёСЃРѕРє РІСЃРµС… РїСЂРѕРµРєС‚РѕРІ РѕСЂРіР°РЅРёР·Р°С†РёРё СЃ РѕР±Р·РѕСЂРЅРѕР№ РёРЅС„РѕСЂРјР°С†РёРµР№
     *
     * GET /api/v1/landing/my-projects
     */
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

                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РћСЂРіР°РЅРёР·Р°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР°',
                ], 404);
            }

            $projects = $this->projectContextService->getAccessibleProjects($organization);

            $projectsData = array_map(function ($projectData) use ($organization) {
                $project = $projectData['project'];
                $role = $projectData['role'];
                $isOwner = $projectData['is_owner'];

                // РџРѕР»СѓС‡Р°РµРј СЃС‚Р°С‚РёСЃС‚РёРєСѓ РїСЂРѕРµРєС‚Р°
                $stats = $this->getProjectStats($project, $organization);

                // РџРѕР»СѓС‡Р°РµРј РїСЂРѕРіСЂРµСЃСЃ РёР· РіСЂР°С„РёРєР°
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

            // Р“СЂСѓРїРїРёСЂСѓРµРј РїРѕ СЂРѕР»Рё
            $groupedProjects = [
                'owned' => array_filter($projectsData, fn($p) => $p['is_owner']),
                'participant' => array_filter($projectsData, fn($p) => !$p['is_owner']),
            ];

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => [
                    'projects' => array_values($projectsData),
                    'grouped' => [
                        'owned' => array_values($groupedProjects['owned']),
                        'participant' => array_values($groupedProjects['participant']),
                    ],
                    'totals' => [
                        'all' => count($projectsData),
                        'owned' => count($groupedProjects['owned']),
                        'participant' => count($groupedProjects['participant']),
                        'active' => count(array_filter($projectsData, fn($p) => !$p['is_archived'])),
                        'archived' => count(array_filter($projectsData, fn($p) => $p['is_archived'])),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get my projects', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'Failed to retrieve projects',
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РґРµС‚Р°Р»СЊРЅСѓСЋ РёРЅС„РѕСЂРјР°С†РёСЋ Рѕ РїСЂРѕРµРєС‚Рµ
     *
     * GET /api/v1/landing/my-projects/{project}
     */
    public function show(Request $request, int $project): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;

            if (!$organization) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РћСЂРіР°РЅРёР·Р°С†РёСЏ РЅРµ РЅР°Р№РґРµРЅР°',
                ], 404);
            }

            $projectModel = Project::find($project);

            if (!$projectModel) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РџСЂРѕРµРєС‚ РЅРµ РЅР°Р№РґРµРЅ',
                ], 404);
            }

            // РџСЂРѕРІРµСЂСЏРµРј РґРѕСЃС‚СѓРї
            if (!$this->projectContextService->canOrganizationAccessProject($projectModel, $organization)) {
                return \App\Http\Responses\LandingResponse::fromPayload([
                    'success' => false,
                    'message' => 'РЈ РІР°СЃ РЅРµС‚ РґРѕСЃС‚СѓРїР° Рє СЌС‚РѕРјСѓ РїСЂРѕРµРєС‚Сѓ',
                ], 403);
            }

            $role = $this->projectContextService->getOrganizationRole($projectModel, $organization);
            $stats = $this->getProjectStats($projectModel, $organization);

            // РџРѕР»СѓС‡Р°РµРј СЂР°СЃС€РёСЂРµРЅРЅС‹Рµ РґР°РЅРЅС‹Рµ
            $schedule = ProjectSchedule::where('project_id', $projectModel->id)->first();

            // Р”Р°РЅРЅС‹Рµ Рѕ Р·Р°РґР°С‡Р°С… (РµСЃР»Рё РµСЃС‚СЊ РіСЂР°С„РёРє)
            $tasksStats = ['open' => 0, 'overdue' => 0, 'completed' => 0, 'total' => 0];
            $nextMilestone = null;

            if ($schedule) {
                $tasksQuery = $schedule->tasks()->where('task_type', '!=', 'project'); // РСЃРєР»СЋС‡Р°РµРј СЃР°Рј РїСЂРѕРµРєС‚ РµСЃР»Рё РѕРЅ Р·Р°РґР°С‡Р°

                // Р•СЃР»Рё СЏ РЅРµ РІР»Р°РґРµР»РµС†, РїРѕРєР°Р·С‹РІР°РµРј Р·Р°РґР°С‡Рё РЅР°Р·РЅР°С‡РµРЅРЅС‹Рµ РЅР° РјРѕРёС… РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№?
                // РР»Рё Р·Р°РґР°С‡Рё РіРґРµ РјРѕСЏ РѕСЂРіР°РЅРёР·Р°С†РёСЏ РёСЃРїРѕР»РЅРёС‚РµР»СЊ? (Р’ ScheduleTask РЅРµС‚ РїРѕР»СЏ organization_id РёСЃРїРѕР»РЅРёС‚РµР»СЏ, РµСЃС‚СЊ assigned_user_id)
                // РџРѕРєР° РїРѕРєР°Р¶РµРј РѕР±С‰СѓСЋ СЃС‚Р°С‚РёСЃС‚РёРєСѓ РїСЂРѕРµРєС‚Р° РґР»СЏ РІР»Р°РґРµР»СЊС†Р°, Рё "РјРѕРё Р·Р°РґР°С‡Рё" РґР»СЏ СѓС‡Р°СЃС‚РЅРёРєР°?
                // Р”Р»СЏ РїСЂРѕСЃС‚РѕС‚С‹ РїРѕРєР°Р¶РµРј РѕР±С‰РёРµ Р·Р°РґР°С‡Рё РїСЂРѕРµРєС‚Р°, С‚Р°Рє РєР°Рє СЌС‚Рѕ "Landing" РґР»СЏ СЂСѓРєРѕРІРѕРґРёС‚РµР»СЏ.

                $tasksStats['total'] = (clone $tasksQuery)->count();
                $tasksStats['completed'] = (clone $tasksQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
                $tasksStats['open'] = (clone $tasksQuery)->whereIn('status', [TaskStatusEnum::NOT_STARTED, TaskStatusEnum::IN_PROGRESS])->count();

                // РџСЂРѕСЃСЂРѕС‡РµРЅРЅС‹Рµ
                $tasksStats['overdue'] = (clone $tasksQuery)
                    ->where('planned_end_date', '<', now())
                    ->where('progress_percent', '<', 100)
                    ->whereNotIn('status', [TaskStatusEnum::COMPLETED, TaskStatusEnum::CANCELLED])
                    ->count();

                // РЎР»РµРґСѓСЋС‰Р°СЏ РІРµС…Р°
                $nextMilestone = $schedule->milestones()
                    ->where('target_date', '>=', now())
                    ->orderBy('target_date')
                    ->first();
            }

            // РЈС‡Р°СЃС‚РЅРёРєРё (С‚РѕРї 5 + РѕР±С‰РµРµ РєРѕР»РёС‡РµСЃС‚РІРѕ)
            $allParticipants = $this->projectContextService->getAllProjectParticipants($projectModel);
            $participantsCount = count($allParticipants);
            $keyParticipants = array_slice($allParticipants, 0, 5);
            $formattedParticipants = array_map(function($p) {
                return [
                    'id' => $p['organization']->id,
                    'name' => $p['organization']->name,
                    'role' => [
                        'value' => $p['role']->value,
                        'label' => $p['role']->label(),
                    ],
                    'logo' => $p['organization']->logo_path,
                ];
            }, $keyParticipants);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => true,
                'data' => [
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
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get project details', [
                'project_id' => $project,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return \App\Http\Responses\LandingResponse::fromPayload([
                'success' => false,
                'message' => 'Failed to retrieve project details',
            ], 500);
        }
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃС‚Р°С‚РёСЃС‚РёРєСѓ РїСЂРѕРµРєС‚Р°
     */
    private function getProjectStats(Project $project, Organization $currentOrganization): array
    {
        try {
            $projectId = $project->id;
            $organizationId = $currentOrganization->id;
            $projectOwnerId = $project->organization_id;

            // РћРїСЂРµРґРµР»СЏРµРј, РєР°РєРёРµ ID РїРѕРґСЂСЏРґС‡РёРєРѕРІ СЃРѕРѕС‚РІРµС‚СЃС‚РІСѓСЋС‚ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё
            // РІ РєРѕРЅС‚РµРєСЃС‚Рµ РІР»Р°РґРµР»СЊС†Р° РїСЂРѕРµРєС‚Р°
            $myContractorIds = [];

            if ($projectOwnerId === $organizationId) {
                // РЇ РІР»Р°РґРµР»РµС† РїСЂРѕРµРєС‚Р°.
                // "РњРѕРё РєРѕРЅС‚СЂР°РєС‚С‹" - СЌС‚Рѕ РєРѕРЅС‚СЂР°РєС‚С‹, РіРґРµ СЏ Р·Р°РєР°Р·С‡РёРє? Р­С‚Рѕ РІСЃРµ РєРѕРЅС‚СЂР°РєС‚С‹ РїСЂРѕРµРєС‚Р°.
                // "РњРѕРё СЂР°Р±РѕС‚С‹" - СЌС‚Рѕ СЂР°Р±РѕС‚С‹, РІС‹РїРѕР»РЅРµРЅРЅС‹Рµ РњРќРћР™ (РµСЃР»Рё СЏ СЃР°Рј РІС‹РїРѕР»РЅСЏСЋ СЂР°Р±РѕС‚С‹).
                // РћР±С‹С‡РЅРѕ РІР»Р°РґРµР»РµС† РЅРµ СЏРІР»СЏРµС‚СЃСЏ РїРѕРґСЂСЏРґС‡РёРєРѕРј Сѓ СЃР°РјРѕРіРѕ СЃРµР±СЏ РІ СЃРёСЃС‚РµРјРµ, РЅРѕ РјРѕР¶РµС‚ Р±С‹С‚СЊ.
                // Р”Р»СЏ СЃС‚Р°С‚РёСЃС‚РёРєРё РІР»Р°РґРµР»СЊС†Р° РїРѕРєР°Р·С‹РІР°РµРј Total. My РјРѕР¶РµС‚ Р±С‹С‚СЊ 0 РёР»Рё "РіРґРµ СЏ РёСЃРїРѕР»РЅРёС‚РµР»СЊ".
                // РџРѕРєР° РѕСЃС‚Р°РІРёРј My = 0, РµСЃР»Рё РЅРµ РЅР°Р№РґРµРј СЃРµР±СЏ РІ РїРѕРґСЂСЏРґС‡РёРєР°С….

                // РџРѕРїСЂРѕР±СѓРµРј РЅР°Р№С‚Рё РїРѕРґСЂСЏРґС‡РёРєР° СЃ РјРѕРёРј РРќРќ (РµСЃР»Рё СЏ СЃР°Рј СЃРµР±СЏ РґРѕР±Р°РІРёР» РєР°Рє РїРѕРґСЂСЏРґС‡РёРєР°)
                 if ($currentOrganization->tax_number) {
                    $myContractorIds = Contractor::where('organization_id', $projectOwnerId)
                        ->where('inn', $currentOrganization->tax_number)
                        ->pluck('id')
                        ->toArray();
                }
            } else {
                // РЇ СѓС‡Р°СЃС‚РЅРёРє. РС‰РµРј СЃРµР±СЏ РІ СЃРїСЂР°РІРѕС‡РЅРёРєРµ РїРѕРґСЂСЏРґС‡РёРєРѕРІ РІР»Р°РґРµР»СЊС†Р° РїСЂРѕРµРєС‚Р°.
                if ($currentOrganization->tax_number) {
                    $myContractorIds = Contractor::where('organization_id', $projectOwnerId)
                        ->where('inn', $currentOrganization->tax_number)
                        ->pluck('id')
                        ->toArray();
                }
            }

            // РЎС‚Р°С‚РёСЃС‚РёРєР° РєРѕРЅС‚СЂР°РєС‚РѕРІ
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

            // РЎС‚Р°С‚РёСЃС‚РёРєР° СЂР°Р±РѕС‚
            $worksQuery = DB::table('completed_works')->where('project_id', $projectId);

            $totalWorks = (clone $worksQuery)->count();
            $totalWorksAmount = (clone $worksQuery)->sum('total_amount');

            $myWorks = 0;
            $myWorksAmount = 0;

            if (!empty($myContractorIds)) {
                // Р’ completed_works РјРѕР¶РµС‚ Р±С‹С‚СЊ contractor_id
                $myWorksQuery = (clone $worksQuery)->whereIn('contractor_id', $myContractorIds);
                $myWorks = $myWorksQuery->count();
                $myWorksAmount = $myWorksQuery->sum('total_amount');
            }

            return [
                'contracts' => [
                    'total' => $totalContracts,
                    'my' => $myContracts,
                    'total_amount' => (float)$totalAmount,
                    'my_amount' => (float)$myContractsAmount,
                ],
                'works' => [
                    'total' => $totalWorks,
                    'my' => $myWorks,
                    'total_amount' => (float)$totalWorksAmount,
                    'my_amount' => (float)$myWorksAmount,
                ],
            ];
        } catch (\Exception $e) {
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
