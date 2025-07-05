<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Project\ProjectService;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Services\Landing\MultiOrganizationService;

class ProjectOrganizationController extends Controller
{
    protected ProjectService $projectService;
    protected MultiOrganizationService $multiOrgService;

    public function __construct(ProjectService $projectService, MultiOrganizationService $multiOrganizationService)
    {
        $this->projectService = $projectService;
        $this->multiOrgService = $multiOrganizationService;
        $this->middleware('can:access-admin-panel');
    }

    public function index(int $projectId): JsonResponse
    {
        try {
            $project = $this->projectService->getProjectDetails($projectId, request());
            if (!$project) {
                return response()->json(['success' => false, 'message' => 'Проект не найден.'], 404);
            }
            $attached = $project->organizations()->get(['organizations.id', 'organizations.name', 'project_organization.role'])->toArray();

            // Добавляем головную организацию проекта
            if ($project->organization) {
                array_unshift($attached, [
                    'id' => $project->organization->id,
                    'name' => $project->organization->name,
                    'role' => 'owner',
                ]);
            }

            return response()->json([
                'success' => true,
                'data' => $attached,
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectOrganizationController@index', ['projectId' => $projectId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера.'], 500);
        }
    }

    public function attach(Request $request, int $projectId, int $organizationId): JsonResponse
    {
        try {
            $this->projectService->addOrganizationToProject($projectId, $organizationId, $request);
            return response()->json(['success' => true, 'message' => 'Организация добавлена к проекту.']);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectOrganizationController@attach', ['projectId' => $projectId, 'orgId' => $organizationId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера.'], 500);
        }
    }

    public function detach(Request $request, int $projectId, int $organizationId): JsonResponse
    {
        try {
            $this->projectService->removeOrganizationFromProject($projectId, $organizationId, $request);
            return response()->json(['success' => true, 'message' => 'Организация удалена из проекта.']);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectOrganizationController@detach', ['projectId' => $projectId, 'orgId' => $organizationId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера.'], 500);
        }
    }

    /**
     * Получить организации, которые можно добавить к проекту.
     */
    public function available(Request $request, int $projectId): JsonResponse
    {
        try {
            $project = $this->projectService->getProjectDetails($projectId, $request);
            if (!$project) {
                return response()->json(['success' => false, 'message' => 'Проект не найден.'], 404);
            }

            $user = $request->user();
            $accessibleOrgs = $this->multiOrgService->getAccessibleOrganizations($user);

            // Исключаем уже привязанные + головную организацию проекта
            $attachedIds = $project->organizations->pluck('id')->push($project->organization_id);
            $available = $accessibleOrgs->whereNotIn('id', $attachedIds)->values()->map(fn($org) => [
                'id' => $org->id,
                'name' => $org->name,
            ]);

            return response()->json(['success' => true, 'data' => $available]);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectOrganizationController@available', ['projectId' => $projectId, 'error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера.'], 500);
        }
    }
} 