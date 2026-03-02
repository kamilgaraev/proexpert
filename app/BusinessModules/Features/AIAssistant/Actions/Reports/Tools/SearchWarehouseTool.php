<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SearchWarehouseTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'search_warehouse';
    }

    public function getDescription(): string
    {
        return 'Ищет склады организации по названию. Позволяет найти ID склада для получения отчетов об остатках.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Название склада или его часть'
                ]
            ],
            'required' => ['query']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $query = $arguments['query'] ?? '';

        // В этом проекте склады хранятся в organization_warehouses
        $warehouses = DB::table('organization_warehouses')
            ->where('organization_id', $organization->id)
            ->where('name', 'ilike', "%{$query}%")
            ->get();

        if ($warehouses->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'Склады не найдены по запросу: ' . $query,
                'results' => []
            ];
        }

        return [
            'status' => 'success',
            'results' => $warehouses->map(fn($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'is_default' => $w->is_default ?? false,
            ])->toArray()
        ];
    }
}
