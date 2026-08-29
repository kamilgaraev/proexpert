<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Services;

use App\Models\User;
use InvalidArgumentException;

final class AccessRecertificationParticipantPolicy
{
    public function assertActiveOrganizationUsers(int $organizationId, array $userIds): void
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $userId): int => (int) $userId, $userIds),
            static fn (int $userId): bool => $userId > 0,
        )));

        if ($normalizedIds === []) {
            return;
        }

        $matchedUsers = User::query()
            ->whereKey($normalizedIds)
            ->where('is_active', true)
            ->whereHas('organizations', function ($query) use ($organizationId): void {
                $query->where('organization_user.organization_id', $organizationId)
                    ->where('organization_user.is_active', true);
            })
            ->count();

        if ($matchedUsers !== count($normalizedIds)) {
            throw new InvalidArgumentException('participant_outside_organization');
        }
    }
}
