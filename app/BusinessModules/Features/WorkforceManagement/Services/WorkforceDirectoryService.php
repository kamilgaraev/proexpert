<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Services;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services\HrAccess;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Auth\Access\AuthorizationException;

final class WorkforceDirectoryService
{
    public function __construct(private readonly HrAccess $access) {}

    public function owners(int $organizationId, ?User $actor): array
    {
        $allowed = false;
        foreach (['workforce.hr.manage', 'workforce.hr.lifecycle.manage', 'workforce.hr.recruitment.manage'] as $permission) {
            $allowed = $allowed || $this->access->allows($organizationId, $actor, $permission);
        }
        if (! $allowed) {
            throw new AuthorizationException(trans_message('workforce.errors.hr_access_denied'));
        }

        return Organization::query()->findOrFail($organizationId)->users()->wherePivot('is_active', true)
            ->orderBy('users.name')->get(['users.id', 'users.name'])->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name])->all();
    }

    public function safetyWorkTypes(int $organizationId, ?User $actor): array
    {
        $this->access->assert($organizationId, $actor, 'workforce.settings.manage');

        return WorkType::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name'])->map(fn (WorkType $type): array => ['id' => (int) $type->id, 'name' => $type->name])->all();
    }
}
