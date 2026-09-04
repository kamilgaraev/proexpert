<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class OrganizationTeamAccess
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function setActive(User $actor, int $organizationId, int $memberId, bool $active): void
    {
        if ($organizationId < 1 || ! $actor->is_active
            || ! $this->authorization->can($actor, 'users.manage', ['organization_id' => $organizationId])) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $organizationId, $memberId, $active): void {
            $memberships = DB::table('organization_user')
                ->where('organization_id', $organizationId)
                ->whereIn('user_id', [(int) $actor->id, $memberId])
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            $actorMembership = $memberships->get((int) $actor->id);
            if ($actorMembership === null || ! (bool) $actorMembership->is_active) {
                throw new AuthorizationException;
            }

            $membership = $memberships->get($memberId);
            $member = $membership === null ? null : User::query()->find($memberId);
            if ($membership === null || $member === null) {
                throw new BusinessLogicException(trans_message('landing_users.team_member_not_found'), 404);
            }

            if (! $active && ((int) $actor->id === $memberId || (bool) $membership->is_owner)) {
                throw new BusinessLogicException(trans_message('landing_users.team_access_protected'), 422);
            }

            if ($active && ! $member->is_active) {
                throw new BusinessLogicException(trans_message('landing_users.team_account_inactive'), 422);
            }

            if ((bool) $membership->is_active === $active) {
                return;
            }

            DB::table('organization_user')
                ->where('organization_id', $organizationId)
                ->where('user_id', $memberId)
                ->update(['is_active' => $active, 'updated_at' => now()]);

            Log::notice('Organization employee access changed', [
                'actor_id' => (int) $actor->id,
                'organization_id' => $organizationId,
                'member_id' => $memberId,
                'is_active' => $active,
            ]);
        }, 3);
    }
}
