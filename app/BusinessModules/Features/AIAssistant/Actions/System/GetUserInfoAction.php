<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\System;

use App\Models\User;

class GetUserInfoAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        // Получаем пользователя из параметров (будет передан из AIAssistantService)
        $userId = $params['user_id'] ?? null;
        
        if (!$userId) {
            return ['error' => 'User not found'];
        }

        $user = User::find($userId);
        
        if (!$user) {
            return ['error' => 'User not found'];
        }

        // Получаем роли пользователя в текущей организации
        $roles = $user->roles()
            ->where('organization_id', $organizationId)
            ->get()
            ->map(fn($role) => [
                'name' => $role->name,
                'slug' => $role->slug,
            ]);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $roles->toArray(),
            ],
            'organization_id' => $organizationId,
        ];
    }
}

