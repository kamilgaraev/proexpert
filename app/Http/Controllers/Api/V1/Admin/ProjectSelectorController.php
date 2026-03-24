<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Project\ProjectContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use function trans_message;

class ProjectSelectorController extends Controller
{
    public function __construct(private readonly ProjectContextService $projectContextService)
    {
    }

    public function availableProjects(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $organization = $user?->currentOrganization;

            if (!$organization) {
                Log::warning('project_selector.organization_not_found', [
                    'user_id' => $user?->id,
                    'current_organization_id' => $user?->current_organization_id,
                ]);

                return AdminResponse::error(
                    trans_message('project_selector.organization_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $projects = $this->projectContextService->getAccessibleProjects($organization);
            $projectsData = array_map(function (array $projectData) {
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
                    'created_at' => $project->created_at->format('Y-m-d'),
                ];
            }, $projects);

            $groupedProjects = [
                'owned' => array_values(array_filter($projectsData, fn (array $project) => $project['is_owner'])),
                'participant' => array_values(array_filter($projectsData, fn (array $project) => !$project['is_owner'])),
            ];

            return AdminResponse::success([
                'projects' => array_values($projectsData),
                'grouped' => $groupedProjects,
                'totals' => [
                    'all' => count($projectsData),
                    'owned' => count($groupedProjects['owned']),
                    'participant' => count($groupedProjects['participant']),
                    'active' => count(array_filter($projectsData, fn (array $project) => !$project['is_archived'])),
                    'archived' => count(array_filter($projectsData, fn (array $project) => $project['is_archived'])),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('project_selector.available_projects.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(
                trans_message('project_selector.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
