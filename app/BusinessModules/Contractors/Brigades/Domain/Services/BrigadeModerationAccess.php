<?php

declare(strict_types=1);

namespace App\BusinessModules\Contractors\Brigades\Domain\Services;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class BrigadeModerationAccess
{
    public function assert(User $actor, string $permission): void
    {
        if ($actor->isSystemAdmin()) {
            return;
        }
        $organizationId = (int) $actor->current_organization_id;
        $context = AuthorizationContext::findOrganizationContext($organizationId);
        if ($context === null
            || !$actor->organizations()->where('organizations.id', $organizationId)->wherePivot('is_active', true)->exists()
            || !UserRoleAssignment::query()->active()->where('user_id', $actor->id)->where('context_id', $context->id)
                ->where('role_type', UserRoleAssignment::TYPE_SYSTEM)->where('role_slug', 'brigade_catalog_moderator')->exists()
            || !app(AuthorizationService::class)->canCurrent($actor, $permission, ['organization_id' => $organizationId])) {
            throw new AuthorizationException(trans_message('brigades.forbidden'));
        }
    }
}
