<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Project;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Contracts\Validation\Rule as RuleContract;
use Illuminate\Support\Facades\Auth;

use function trans_message;

class ProjectAccessibleRule implements RuleContract
{
    public function __construct(private readonly ?int $organizationId = null, private readonly bool $includeArchived = false)
    {
    }

    public function passes($attribute, $value): bool
    {
        $user = Auth::user();
        $currentOrgId = $this->organizationId ?? $user?->current_organization_id;

        if (!$user instanceof User || !$currentOrgId || !is_numeric($value)) {
            return false;
        }

        $project = Project::query()->find((int) $value);

        if (!$project instanceof Project) {
            return false;
        }

        return app(UserProjectAccessService::class)->queryAccessibleProjects($user, (int) $currentOrgId, $this->includeArchived)
            ->where('projects.id', $project->id)->exists();
    }

    public function message(): string
    {
        return trans_message('project.not_found_or_access_denied');
    }
}
