<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use RuntimeException;

abstract class CustomerController extends Controller
{
    protected function hasPermission(Request $request, string $permission, ?int $organizationId = null): bool
    {
        $user = $request->user();

        if (!$user) {
            return false;
        }

        $context = [
            'organization_id' => $organizationId ?? $this->resolveOrganizationId($request),
        ];

        return app(AuthorizationService::class)->can($user, $permission, $context);
    }

    protected function resolveOrganizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new RuntimeException('Customer organization context is missing.');
        }

        return (int) $organizationId;
    }

    protected function canAccessProject(Project $project, int $organizationId, ?User $user = null): bool
    {
        $hasOrganizationAccess = $project->organization_id === $organizationId
            || $project->organizations()
                ->where('organizations.id', $organizationId)
                ->where('project_organization.is_active', true)
                ->exists();

        if (!$hasOrganizationAccess) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        $scopedProjectIds = $user->assignedProjects()
            ->where(function ($builder) use ($organizationId): void {
                $builder
                    ->where('projects.organization_id', $organizationId)
                    ->orWhereExists(function ($subQuery) use ($organizationId): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('project_organization')
                            ->whereColumn('project_organization.project_id', 'projects.id')
                            ->where('project_organization.organization_id', $organizationId)
                            ->where('project_organization.is_active', true);
                    });
            })
            ->pluck('projects.id');

        if ($scopedProjectIds->isEmpty()) {
            return true;
        }

        return $scopedProjectIds->contains($project->id);
    }
}
