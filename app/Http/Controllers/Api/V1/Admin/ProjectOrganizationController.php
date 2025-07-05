<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Project\ProjectService;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProjectOrganizationController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
        $this->middleware('can:access-admin-panel');
    }

    public function index(int $projectId): JsonResponse
    {
        try {
            $project = $this->projectService->getProjectDetails($projectId, request());
            if (!$project) {
                return response()->json(['success' => false, 'message' => 'Проект не найден.'], 404);
            }
            return response()->json([
                'success' => true,
                'data' => $project->organizations()->get(['organizations.id', 'organizations.name', 'project_organization.role'])
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
} 