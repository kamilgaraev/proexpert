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
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use App\Exceptions\BusinessLogicException;
use App\DTOs\Project\ProjectDTO;
use Illuminate\Http\Response;

class ProjectController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
        // Авторизация настроена на уровне роутов через middleware стек
        $this->middleware('subscription.limit:max_projects')->only('store');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = $request->query('per_page', 15);
            $projects = $this->projectService->getProjectsForCurrentOrg($request, (int)$perPage);
            return AdminResponse::success(ProjectResource::collection($projects));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@index', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.list_error'), 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProjectRequest $request): JsonResponse
    {
        try {
            $projectDTO = $request->toDto();

            $project = $this->projectService->createProject($projectDTO, $request);
            return AdminResponse::success(new ProjectResource($project), trans_message('project.created'), Response::HTTP_CREATED);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@store', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.create_error'), 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $project = $this->projectService->findProjectByIdForCurrentOrg((int)$id, $request);
            if (!$project) {
                return AdminResponse::error(trans_message('project.not_found'), 404);
            }
            return AdminResponse::success(new ProjectResource($project->load(['users', 'costCategory'])));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@show', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.show_error'), 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProjectRequest $request, string $id): JsonResponse
    {
        try {
            $project = $this->projectService->updateProject((int)$id, $request->toDto(), $request);
            if (!$project) {
                return AdminResponse::error(trans_message('project.update_not_found'), 404);
            }
            return AdminResponse::success(new ProjectResource($project), trans_message('project.updated'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@update', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.update_error'), 500);
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
                return AdminResponse::error(trans_message('project.delete_not_found'), 404);
            }
            return AdminResponse::success(null, trans_message('project.deleted'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@destroy', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.delete_error'), 500);
        }
    }

    /**
     * Назначить прораба на проект.
     */
    public function assignForeman(Request $request, string $projectId, string $userId): JsonResponse
    {
        try {
            $this->projectService->assignForemanToProject((int)$projectId, (int)$userId, $request);
            return AdminResponse::success(null, trans_message('project.foreman_assigned'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@assignForeman', [
                'projectId' => $projectId, 'userId' => $userId, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.foreman_assign_error'), 500);
        }
    }

    /**
     * Открепить прораба от проекта.
     */
    public function detachForeman(Request $request, string $projectId, string $userId): JsonResponse
    {
        try {
            $this->projectService->detachForemanFromProject((int)$projectId, (int)$userId, $request);
            return AdminResponse::success(null, trans_message('project.foreman_detached'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@detachForeman', [
                'projectId' => $projectId, 'userId' => $userId, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.foreman_detach_error'), 500);
        }
    }

    public function statistics(int $id): JsonResponse
    {
        try {
            $statistics = $this->projectService->getProjectStatistics($id);
            return AdminResponse::success($statistics);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@statistics', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.statistics_error'), 500);
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
            return AdminResponse::success($materials);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@getProjectMaterials', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.materials_error'), 500);
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
            return AdminResponse::success($workTypes);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@getProjectWorkTypes', [
                'id' => $id, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.work_types_error'), 500);
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
            
            return AdminResponse::success($costCategories);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@getAvailableCostCategories', [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()
            ]);
            return AdminResponse::error(trans_message('project.cost_categories_error'), 500);
        }
    }

    /**
     * Полная сводка по проекту.
     */
    public function fullDetails(Request $request, int $id): JsonResponse
    {
        try {
            $details = $this->projectService->getFullProjectDetails($id, $request);
            return AdminResponse::success($details);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in ProjectController@fullDetails', [
                'projectId' => $id,
                'error' => $e->getMessage(),
            ]);
            return AdminResponse::error(trans_message('project.full_details_error'), 500);
        }
    }
} 