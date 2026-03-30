<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use RuntimeException;

abstract class CustomerController extends Controller
{
    protected function resolveOrganizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new RuntimeException('Customer organization context is missing.');
        }

        return (int) $organizationId;
    }

    protected function canAccessProject(Project $project, int $organizationId): bool
    {
        return $project->organization_id === $organizationId
            || $project->organizations()
                ->where('organizations.id', $organizationId)
                ->where('project_organization.is_active', true)
                ->exists();
    }
}
