<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;

class SearchProjectsTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'search_projects';
    }

    public function getDescription(): string
    {
        return 'Ищет проекты организации по названию или адресу. Позволяет найти ID проекта для использования в других инструментах.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Название проекта или часть адреса'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество результатов (по умолчанию 5)',
                    'default' => 5
                ]
            ],
            'required' => ['query']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $query = $arguments['query'] ?? '';
        $limit = $arguments['limit'] ?? 5;

        $projects = Project::where('organization_id', $organization->id)
            ->where(function($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                  ->orWhere('address', 'ilike', "%{$query}%");
            })
            ->limit($limit)
            ->get();

        if ($projects->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'Проекты не найдены по запросу: ' . $query,
                'results' => []
            ];
        }

        return [
            'status' => 'success',
            'results' => $projects->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'address' => $p->address,
                'status' => $p->status,
            ])->toArray()
        ];
    }
}
