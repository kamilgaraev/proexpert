<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\ProjectContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MyProjectsController extends Controller
{
    protected ProjectContextService $projectContextService;

    public function __construct(ProjectContextService $projectContextService)
    {
        $this->projectContextService = $projectContextService;
    }

    /**
     * Получить список всех проектов организации с обзорной информацией
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
                
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $projects = $this->projectContextService->getAccessibleProjects($organization);
            
            $projectsData = array_map(function ($projectData) use ($organization) {
                $project = $projectData['project'];
                $role = $projectData['role'];
                $isOwner = $projectData['is_owner'];
                
                // Получаем статистику проекта
                $stats = $this->getProjectStats($project->id, $organization->id);
                
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
                ];
            }, $projects);
            
            // Группируем по роли
            $groupedProjects = [
                'owned' => array_filter($projectsData, fn($p) => $p['is_owner']),
                'participant' => array_filter($projectsData, fn($p) => !$p['is_owner']),
            ];
            
            return response()->json([
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
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve projects',
            ], 500);
        }
    }

    /**
     * Получить детальную информацию о проекте
     * 
     * GET /api/v1/landing/my-projects/{project}
     */
    public function show(Request $request, int $project): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user->currentOrganization;
            
            if (!$organization) {
                Log::warning('User has no current organization', [
                    'user_id' => $user->id,
                    'current_organization_id' => $user->current_organization_id,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Организация не найдена',
                ], 404);
            }
            
            $projectModel = \App\Models\Project::find($project);
            
            if (!$projectModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Проект не найден',
                ], 404);
            }
            
            // Проверяем доступ
            if (!$this->projectContextService->canOrganizationAccessProject($projectModel, $organization)) {
                return response()->json([
                    'success' => false,
                    'message' => 'У вас нет доступа к этому проекту',
                ], 403);
            }
            
            $role = $this->projectContextService->getOrganizationRole($projectModel, $organization);
            $stats = $this->getProjectStats($project, $organization->id);
            $participants = $this->projectContextService->getAllProjectParticipants($projectModel);
            
            return response()->json([
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
                    'participants_count' => count($participants),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get project details', [
                'project_id' => $project,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve project details',
            ], 500);
        }
    }

    /**
     * Получить статистику проекта
     */
    private function getProjectStats(int $projectId, int $organizationId): array
    {
        try {
            // Статистика контрактов
            $contractsStats = DB::table('contracts')
                ->where('project_id', $projectId)
                ->select(
                    DB::raw('COUNT(*) as total_contracts'),
                    DB::raw('SUM(CASE WHEN contractor_id = ? THEN 1 ELSE 0 END) as my_contracts', [$organizationId]),
                    DB::raw('SUM(total_amount) as total_amount'),
                    DB::raw('SUM(CASE WHEN contractor_id = ? THEN total_amount ELSE 0 END) as my_amount', [$organizationId])
                )
                ->first();
            
            // Статистика работ
            $worksStats = DB::table('completed_works')
                ->where('project_id', $projectId)
                ->select(
                    DB::raw('COUNT(*) as total_works'),
                    DB::raw('SUM(CASE WHEN contractor_id = ? THEN 1 ELSE 0 END) as my_works', [$organizationId]),
                    DB::raw('SUM(total_amount) as total_amount'),
                    DB::raw('SUM(CASE WHEN contractor_id = ? THEN total_amount ELSE 0 END) as my_amount', [$organizationId])
                )
                ->first();
            
            return [
                'contracts' => [
                    'total' => $contractsStats->total_contracts ?? 0,
                    'my' => $contractsStats->my_contracts ?? 0,
                    'total_amount' => $contractsStats->total_amount ?? 0,
                    'my_amount' => $contractsStats->my_amount ?? 0,
                ],
                'works' => [
                    'total' => $worksStats->total_works ?? 0,
                    'my' => $worksStats->my_works ?? 0,
                    'total_amount' => $worksStats->total_amount ?? 0,
                    'my_amount' => $worksStats->my_amount ?? 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to get project stats', [
                'project_id' => $projectId,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'contracts' => ['total' => 0, 'my' => 0, 'total_amount' => 0, 'my_amount' => 0],
                'works' => ['total' => 0, 'my' => 0, 'total_amount' => 0, 'my_amount' => 0],
            ];
        }
    }
}
