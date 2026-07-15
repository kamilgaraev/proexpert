<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;

final readonly class OrganizationUserChannel
{
    public function __construct(private UserChannel $userChannel) {}

    public function join(
        User $user,
        int|string $id,
        string $interface,
        int|string $organizationId
    ): bool {
        $trustedOrganizationId = (int) $organizationId;

        return $trustedOrganizationId > 0
            && $this->userChannel->join($user, $id, $interface)
            && $user->belongsToOrganization($trustedOrganizationId);
    }
}
