<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\System;

use App\Models\Organization;
use App\Models\User;

class GetTeamInfoAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $organization = Organization::find($organizationId);
        
        if (!$organization) {
            return ['error' => 'Organization not found'];
        }

        // Получаем всех пользователей организации
        $users = User::whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organizations.id', $organizationId);
        })
        ->with(['roles' => function ($query) use ($organizationId) {
            $query->where('organization_id', $organizationId);
        }])
        ->get()
        ->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->map(fn($role) => $role->name)->toArray(),
            ];
        });

        // Группировка по ролям
        $byRole = $users->groupBy(function ($user) {
            return $user['roles'][0] ?? 'Без роли';
        });

        return [
            'total_users' => $users->count(),
            'users' => $users->toArray(),
            'by_role' => $byRole->map->count()->toArray(),
        ];
    }
}

