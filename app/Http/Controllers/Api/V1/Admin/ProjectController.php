<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use App\Models\CostCategory;
use App\Services\Project\ProjectService;
use App\Http\Requests\Api\V1\Admin\Project\StoreProjectRequest;
use App\Http\Requests\Api\V1\Admin\Project\UpdateProjectRequest;
use App\Http\Resources\Api\V1\Admin\Project\ProjectResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;
use App\DTOs\Project\ProjectDTO;

class ProjectController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $projects = $this->projectService->getProjectsForCurrentOrg($request, (int)$perPage);
            return ProjectResource::collection($projects);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@index', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении списка проектов.',
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): ProjectResource | JsonResponse
    {
        try {
            $projectDTO = $request->toDto();

            $project = $this->projectService->createProject($projectDTO, $request);
            return new ProjectResource($project);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@store', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при создании проекта.',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id): ProjectResource | JsonResponse
    {
        try {
            $project = $this->projectService->findProjectByIdForCurrentOrg((int)$id, $request);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Проект не найден в вашей организации.'
                ], 404);
            }
            return new ProjectResource($project->load(['users', 'costCategory']));
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@show', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении проекта.',
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, string $id): ProjectResource | JsonResponse
    {
        try {
            $project = $this->projectService->updateProject((int)$id, $request->validated(), $request);
            if (!$project) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось обновить проект или проект не найден.'
                ], 404);
            }
            return new ProjectResource($project);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@update', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при обновлении проекта.',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $success = $this->projectService->deleteProject((int)$id, $request);
            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Не удалось удалить проект или проект не найден.'
                ], 404);
            }
            return response()->json([
                'success' => true,
                'message' => 'Проект успешно удален.'
            ], 200);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@destroy', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при удалении проекта.',
            ], 500);
        }
    }

    /**
     * Назначить прораба на проект.
     */
    public function assignForeman(Request $request, string $projectId, string $userId): JsonResponse
    {
        try {
            $this->projectService->assignForemanToProject((int)$projectId, (int)$userId, $request);
            // Если assignForemanToProject не выбросил исключение, считаем успешным
            return response()->json([
                'success' => true,
                'message' => 'Прораб успешно назначен на проект.'
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@assignForeman', [
                'projectId' => $projectId, 'userId' => $userId, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при назначении прораба на проект.',
            ], 500);
        }
    }

    /**
     * Открепить прораба от проекта.
     */
    public function detachForeman(Request $request, string $projectId, string $userId): JsonResponse
    {
        try {
            $this->projectService->detachForemanFromProject((int)$projectId, (int)$userId, $request);
            // Если detachForemanFromProject не выбросил исключение, считаем успешным
            return response()->json([
                'success' => true,
                'message' => 'Прораб успешно откреплен от проекта.'
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@detachForeman', [
                'projectId' => $projectId, 'userId' => $userId, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при откреплении прораба от проекта.',
            ], 500);
        }
    }

    public function statistics(int $id): JsonResponse
    {
        try {
            $statistics = $this->projectService->getProjectStatistics($id);
            return response()->json([
                'success' => true, // Условно true, так как метод-заглушка отработал
                'data' => $statistics
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@statistics', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении статистики по проекту.',
            ], 500);
        }
    }

    public function getProjectMaterials(int $id, Request $request): JsonResponse
    {
        try {
            $materials = $this->projectService->getProjectMaterials(
                $id,
                $request->get('per_page', 15),
                $request->get('search'),
                $request->get('sort_by', 'created_at'),
                $request->get('sort_direction', 'desc')
            );
            return response()->json([
                'success' => true, // Условно true
                'data' => $materials
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@getProjectMaterials', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении материалов проекта.',
            ], 500);
        }
    }

    public function getProjectWorkTypes(int $id, Request $request): JsonResponse
    {
        try {
            $workTypes = $this->projectService->getProjectWorkTypes(
                $id,
                $request->get('per_page', 15),
                $request->get('search'),
                $request->get('sort_by', 'created_at'),
                $request->get('sort_direction', 'desc')
            );
            return response()->json([
                'success' => true, // Условно true
                'data' => $workTypes
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@getProjectWorkTypes', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении видов работ проекта.',
            ], 500);
        }
    }

    /**
     * Получить список категорий затрат для выбора при создании/редактировании проекта.
     */
    public function getAvailableCostCategories(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->user()->current_organization_id;
            
            $costCategories = CostCategory::activeForOrganization($organizationId)
                ->get(['id', 'name', 'code', 'external_code', 'parent_id']);
            
            return response()->json([
                'success' => true,
                'data' => $costCategories
            ]);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@getAvailableCostCategories', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Внутренняя ошибка сервера при получении категорий затрат.',
            ], 500);
        }
    }
} 