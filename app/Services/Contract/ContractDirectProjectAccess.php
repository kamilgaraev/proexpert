<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Organization;
use App\Models\Project;
use App\Services\Project\ProjectContextService;
use Illuminate\Auth\Access\AuthorizationException;

final class ContractDirectProjectAccess
{
    public static function assertAllowed(int $organizationId, array $projects): void
    {
        if ($projects === []) return;
        $organization = Organization::findOrFail($organizationId);
        foreach ($projects as $project) {
            $context = app(ProjectContextService::class)->getContext($project, $organization);
            $allowed = $project->contracting_scheme === 'direct' ? ['owner', 'customer', 'contractor'] : ['owner', 'general_contractor', 'contractor'];
            if (! $context->roleConfig->canManageContracts || ! in_array($context->roleConfig->role->value, $allowed, true)) throw new AuthorizationException;
        }
    }
}
