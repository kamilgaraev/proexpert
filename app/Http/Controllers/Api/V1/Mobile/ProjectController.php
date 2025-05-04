<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Project\ProjectService;
use App\Http\Resources\Api\V1\Mobile\Project\MobileProjectResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class ProjectController extends Controller
{
    protected ProjectService $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
    }

    /**
     * Получить список проектов, назначенных текущему пользователю (прорабу).
     */
    public function getAssignedProjects(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            // Сервис сам определит пользователя и организацию из реквеста
            $projects = $this->projectService->getProjectsForUser($request);
            return MobileProjectResource::collection($projects);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error fetching assigned projects for mobile user', [
                'user_id' => $request->user()?->id,
                'exception' => $e
            ]);
            return response()->json(['message' => 'Произошла внутренняя ошибка сервера.'], 500);
        }
    }

    // Можно добавить другие методы для мобильного приложения, если понадобятся
    // Например, получение деталей конкретного проекта, доступного прорабу
    /*
    public function show(Request $request, int $projectId): MobileProjectResource | JsonResponse
    {
        try {
            $project = $this->projectService->getProjectDetailsForUser($request, $projectId); // Нужен новый метод в сервисе
            if (!$project) {
                return response()->json(['message' => 'Проект не найден или недоступен.'], 404);
            }
            return new MobileProjectResource($project);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error fetching project details for mobile user', [
                'user_id' => $request->user()?->id,
                'project_id' => $projectId,
                'exception' => $e
            ]);
            return response()->json(['message' => 'Произошла внутренняя ошибка сервера.'], 500);
        }
    }
    */
}
