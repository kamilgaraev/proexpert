<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\System;

use App\Models\Organization;
use App\Models\User;
use App\Domain\Authorization\Models\AuthorizationContext;
use Illuminate\Support\Facades\DB;

class GetTeamInfoAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $organization = Organization::find($organizationId);
        
        if (!$organization) {
            return ['error' => 'Organization not found'];
        }

        $context = AuthorizationContext::getOrganizationContext($organizationId);
        
        if (!$context) {
            return ['error' => 'Organization context not found'];
        }

        $users = User::whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organizations.id', $organizationId)
                  ->where('organization_user.is_active', true);
        })
        ->get()
        ->map(function ($user) use ($context) {
            $roles = $user->getRoles($context);
            $roleNames = $roles->pluck('role_slug')->toArray();
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'roles' => $roleNames,
            ];
        });

        $byRole = $users->groupBy(function ($user) {
            return $user['roles'][0] ?? 'Без роли';
        })->map->count();

        return [
            'total_users' => $users->count(),
            'users' => $users->values()->toArray(),
            'by_role' => $byRole->toArray(),
            'organization_name' => $organization->name,
        ];
    }
}

