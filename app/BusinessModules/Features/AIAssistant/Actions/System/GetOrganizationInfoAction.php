<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\System;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

class GetOrganizationInfoAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $organization = Organization::find($organizationId);
        
        if (!$organization) {
            return ['error' => 'Organization not found'];
        }

        // Подсчет проектов
        $projectsCount = Project::where('organization_id', $organizationId)->count();
        $activeProjectsCount = Project::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->count();

        // Подсчет пользователей
        $usersCount = User::whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organizations.id', $organizationId);
        })->count();

        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'type' => $organization->type ?? 'Не указан',
                'inn' => $organization->inn ?? 'Не указан',
                'created_at' => $organization->created_at->format('d.m.Y'),
            ],
            'statistics' => [
                'total_projects' => $projectsCount,
                'active_projects' => $activeProjectsCount,
                'total_users' => $usersCount,
            ],
        ];
    }
}

