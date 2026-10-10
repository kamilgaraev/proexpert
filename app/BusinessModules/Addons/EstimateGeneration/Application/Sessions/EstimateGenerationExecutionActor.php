<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\Models\SystemAdmin;
use App\Models\User;

final class EstimateGenerationExecutionActor
{
    public static function identity(User|SystemAdmin $actor): array
    {
        return ['generation_actor_type' => $actor instanceof SystemAdmin ? 'system_admin' : 'user',
            'generation_actor_id' => (int) $actor->getKey()];
    }

    public static function resolve(array $input, int $fallbackUserId): User|SystemAdmin|null
    {
        $id = (int) ($input['generation_actor_id'] ?? $fallbackUserId);

        return match ($input['generation_actor_type'] ?? 'user') {
            'user' => User::query()->find($id),
            'system_admin' => SystemAdmin::query()->find($id),
            default => null,
        };
    }
}
