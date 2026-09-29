<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionStateService;
use App\Models\User;

final readonly class DesignModelSessionChannel
{
    public function __construct(private DesignModelSessionAccessService $access)
    {
    }

    public function join(User $user, int|string $sessionId): array|bool
    {
        $organizationId = request()->attributes->get('current_organization_id');
        if (! $this->access->canJoin($user, (int) $sessionId, $organizationId !== null ? (int) $organizationId : null)) {
            return false;
        }

        return DesignModelSessionStateService::sender($user);
    }
}
