<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionAccessService;
use App\Models\User;

final readonly class DesignModelSessionChannel
{
    public function __construct(private DesignModelSessionAccessService $access)
    {
    }

    public function join(User $user, int|string $sessionId): array|bool
    {
        if (! $this->access->canJoin($user, (int) $sessionId)) {
            return false;
        }

        return ['id' => (int) $user->id, 'name' => (string) $user->name];
    }
}
