<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\System;

use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;

class GetUserInfoAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $userId = $params['user_id'] ?? null;
        
        if (!$userId) {
            return ['error' => 'User not found'];
        }

        $user = User::find($userId);
        
        if (!$user) {
            return ['error' => 'User not found'];
        }

        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        if (!$context) {
            return ['error' => 'Organization context not found'];
        }

        $roles = $user->getRoles($context);
        $roleNames = $roles->pluck('role_slug')->toArray();

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'roles' => $roleNames,
                'is_admin' => $user->isOrganizationAdmin($organizationId),
                'is_owner' => $user->isOrganizationOwner($organizationId),
            ],
            'organization_id' => $organizationId,
        ];
    }
}

