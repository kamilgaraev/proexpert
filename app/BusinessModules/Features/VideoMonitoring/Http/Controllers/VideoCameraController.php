<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Http\Controllers;

use App\BusinessModules\Features\VideoMonitoring\Http\Requests\CheckVideoConnectionRequest;
use App\BusinessModules\Features\VideoMonitoring\Http\Requests\StoreVideoCameraRequest;
use App\BusinessModules\Features\VideoMonitoring\Http\Requests\UpdateVideoCameraRequest;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\BusinessModules\Features\VideoMonitoring\Services\VideoCameraService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class VideoCameraController extends Controller
{
    public function __construct(
        private readonly VideoCameraService $service
    ) {
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->getProjectDashboard($project, $request->user())
            );
        } catch (Throwable $exception) {
            Log::error('Error in VideoCameraController@index', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('video_monitoring.load_error', [], 'ru'), 500);
        }
    }

    public function store(StoreVideoCameraRequest $request, Project $project): JsonResponse
    {
        try {
            $camera = $this->service->create($project, $request->validated(), $request->user());

            return AdminResponse::success($camera, trans_message('video_monitoring.created', [], 'ru'), 201);
        } catch (Throwable $exception) {
            Log::error('Error in VideoCameraController@store', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function update(UpdateVideoCameraRequest $request, Project $project, VideoCamera $camera): JsonResponse
    {
        try {
            $updatedCamera = $this->service->update($project, $camera, $request->validated(), $request->user());

            return AdminResponse::success($updatedCamera, trans_message('video_monitoring.updated', [], 'ru'));
        } catch (Throwable $exception) {
            Log::error('Error in VideoCameraController@update', [
                'project_id' => $project->id,
                'camera_id' => $camera->id,
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function destroy(Request $request, Project $project, VideoCamera $camera): JsonResponse
    {
        try {
            $this->service->delete($project, $camera, $request->user());

            return AdminResponse::success(null, trans_message('video_monitoring.deleted', [], 'ru'));
        } catch (Throwable $exception) {
            Log::error('Error in VideoCameraController@destroy', [
                'project_id' => $project->id,
                'camera_id' => $camera->id,
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function check(CheckVideoConnectionRequest $request, Project $project): JsonResponse
    {
        try {
            $probe = $this->service->check($project, $request->validated(), $request->user());

            return AdminResponse::success($probe, $probe['message'] ?? null);
        } catch (Throwable $exception) {
            Log::error('Error in VideoCameraController@check', [
                'project_id' => $project->id,
                'user_id' => $request->user()?->id,
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
