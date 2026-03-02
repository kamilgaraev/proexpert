<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\User;

class SearchContractorsTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'search_contractors';
    }

    public function getDescription(): string
    {
        return 'Ищет контрагентов (подрядчиков/поставщиков) организации по названию или ИНН. Возвращает список подходящих контрагентов с их ID.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Название контрагента или ИНН'
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

        $contractors = Contractor::where('organization_id', $organization->id)
            ->where(function($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                  ->orWhere('inn', 'like', "%{$query}%");
            })
            ->limit($limit)
            ->get();

        if ($contractors->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'Контрагенты не найдены по запросу: ' . $query,
                'results' => []
            ];
        }

        return [
            'status' => 'success',
            'results' => $contractors->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'inn' => $c->inn,
            ])->toArray()
        ];
    }
}
