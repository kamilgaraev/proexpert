<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\ProjectCommandCenter\Services\ProjectCommandCenterService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use App\Models\User;
use App\Services\Admin\AdminProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectCommandCenterController extends Controller
{
    public function __construct(
        private readonly AdminProjectAccessService $projectAccessService,
        private readonly ProjectCommandCenterService $commandCenterService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'period' => ['sometimes', 'string', Rule::in(['month', 'quarter', 'project', 'custom'])],
                'date_from' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom'],
                'date_to' => ['nullable', 'date_format:Y-m-d', 'required_if:period,custom', 'after_or_equal:date_from'],
            ]);

            $project = Project::query()->find($validated['project_id']);

            if (!$project instanceof Project) {
                return AdminResponse::error(trans_message('project.not_found_or_access_denied'), Response::HTTP_NOT_FOUND);
            }

            $user = Auth::user();
            $projectContext = $user instanceof User
                ? $this->projectAccessService->getProjectContext($project, $user)
                : null;

            if ($projectContext === null) {
                return AdminResponse::error(trans_message('dashboard.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $data = $this->commandCenterService->build(
                project: $project,
                period: $validated['period'] ?? 'project',
                dateFrom: $validated['date_from'] ?? null,
                dateTo: $validated['date_to'] ?? null,
            );

            return AdminResponse::success($data->toArray());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Error in ProjectCommandCenterController@show', [
                'project_id' => $request->input('project_id'),
                'user_id' => Auth::id(),
                'message' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('project.dashboard_fetch_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
