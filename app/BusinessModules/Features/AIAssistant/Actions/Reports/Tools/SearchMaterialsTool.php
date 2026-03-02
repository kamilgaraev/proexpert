<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Material;
use App\Models\Organization;
use App\Models\User;

class SearchMaterialsTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'search_materials';
    }

    public function getDescription(): string
    {
        return 'Ищет материалы в каталоге организации по названию. Возвращает список подходящих материалов с их ID и единицами измерения.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Название материала или часть названия для поиска'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество результатов (по умолчанию 10)',
                    'default' => 10
                ]
            ],
            'required' => ['query']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $query = $arguments['query'] ?? '';
        $limit = $arguments['limit'] ?? 10;

        $materials = Material::where('organization_id', $organization->id)
            ->where('name', 'ilike', "%{$query}%")
            ->with('measurementUnit')
            ->limit($limit)
            ->get();

        if ($materials->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'Материалы не найдены по запросу: ' . $query,
                'results' => []
            ];
        }

        return [
            'status' => 'success',
            'results' => $materials->map(fn($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'measurement_unit' => $m->measurementUnit?->name ?? 'н/д',
                'measurement_unit_id' => $m->measurement_unit_id,
            ])->toArray()
        ];
    }
}
