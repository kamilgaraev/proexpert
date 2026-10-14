<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Services;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class HrAccess
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function assert(int $organizationId, ?User $actor, string $permission = 'workforce.hr.manage'): void
    {
        if (! $this->allows($organizationId, $actor, $permission)) {
            throw new AuthorizationException(trans_message('workforce.errors.hr_access_denied'));
        }
    }

    public function allows(int $organizationId, ?User $actor, string $permission): bool
    {
        return $organizationId > 0 && $actor !== null
            && $actor->belongsToOrganization($organizationId)
            && $this->authorization->canCurrent($actor, $permission, ['organization_id' => $organizationId]);
    }

    public function owner(int $organizationId, ?int $userId): void
    {
        if ($userId !== null && ! User::query()->find($userId)?->belongsToOrganization($organizationId)) {
            throw new \DomainException(trans_message('workforce.errors.user_not_found'));
        }
    }
}
