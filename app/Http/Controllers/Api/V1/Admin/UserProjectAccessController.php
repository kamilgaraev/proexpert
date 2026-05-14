<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserProjectAccessMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UserProjectAccess\UpdateUserProjectAccessRequest;
use App\Http\Resources\Api\V1\Admin\UserProjectAccessResource;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class UserProjectAccessController extends Controller
{
    public function __construct(private readonly UserProjectAccessService $projectAccessService)
    {
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if (!$this->isUserInOrganization($user, $organizationId)) {
            return AdminResponse::error(trans_message('user.not_found'), Response::HTTP_NOT_FOUND);
        }

        return AdminResponse::success(new UserProjectAccessResource($this->payload($user, $organizationId)));
    }

    public function update(UpdateUserProjectAccessRequest $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $organizationId = $this->resolveOrganizationId($request);

        if (!$actor || !$this->isUserInOrganization($user, $organizationId)) {
            return AdminResponse::error(trans_message('user.not_found'), Response::HTTP_NOT_FOUND);
        }

        $mode = UserProjectAccessMode::from($request->validated('project_access_mode'));
        $projectIds = array_map('intval', $request->validated('project_ids', []));

        DB::transaction(function () use ($user, $organizationId, $mode, $projectIds, $actor): void {
            DB::table('organization_user')
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->update([
                    'project_access_mode' => $mode->value,
                    'updated_at' => now(),
                ]);

            if ($mode === UserProjectAccessMode::ASSIGNED_PROJECTS) {
                $this->projectAccessService->syncAssignments($user, $organizationId, $projectIds, $actor);
            }
        });

        return AdminResponse::success(
            new UserProjectAccessResource($this->payload($user, $organizationId)),
            trans_message('user_project_access.updated')
        );
    }

    private function payload(User $user, int $organizationId): array
    {
        $availableProjects = Project::query()
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['projects.id', 'projects.name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])
            ->values()
            ->all();

        $assignedProjects = $user->assignedProjects()
            ->wherePivot('is_active', true)
            ->whereIn('projects.id', array_column($availableProjects, 'id'))
            ->orderBy('projects.name')
            ->get(['projects.id', 'projects.name']);

        return [
            'user' => $user,
            'project_access_mode' => $this->projectAccessService->modeFor($user, $organizationId)->value,
            'project_ids' => $assignedProjects->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'project_access' => $assignedProjects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])->values()->all(),
            'available_projects' => $availableProjects,
        ];
    }

    private function resolveOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
            ?? 0);
    }

    private function isUserInOrganization(User $user, int $organizationId): bool
    {
        return $organizationId > 0
            && $user->organizations()
                ->where('organization_user.organization_id', $organizationId)
                ->where('organization_user.is_active', true)
                ->exists();
    }
}
