<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\Project\ProjectContextService;
use Illuminate\Support\Facades\Log;

class ProjectSelectorController extends Controller
{
    protected ProjectContextService $projectContextService;

    public function __construct(ProjectContextService $projectContextService)
    {
        $this->projectContextService = $projectContextService;
    }

    /**
     * Получить список доступных проектов для выбора в админке
     * 
     * GET /api/v1/admin/available-projects
     */
    public function availableProjects(Request $request): JsonResponse
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
            
            $projectsData = array_map(function ($projectData) {
                $project = $projectData['project'];
                $role = $projectData['role'];
                $isOwner = $projectData['is_owner'];
                
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'address' => $project->address,
                    'status' => $project->status,
                    'start_date' => $project->start_date?->format('Y-m-d'),
                    'end_date' => $project->end_date?->format('Y-m-d'),
                    'is_archived' => $project->is_archived,
                    'role' => $role->value,
                    'role_label' => $role->label(),
                    'is_owner' => $isOwner,
                    // Дополнительная информация для карточек
                    'created_at' => $project->created_at->format('Y-m-d'),
                ];
            }, $projects);
            
            // Группируем по типу участия
            $groupedProjects = [
                'owned' => array_values(array_filter($projectsData, fn($p) => $p['is_owner'])),
                'participant' => array_values(array_filter($projectsData, fn($p) => !$p['is_owner'])),
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'projects' => array_values($projectsData),
                    'grouped' => $groupedProjects,
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
            Log::error('Failed to get available projects for admin', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available projects',
            ], 500);
        }
    }
}
