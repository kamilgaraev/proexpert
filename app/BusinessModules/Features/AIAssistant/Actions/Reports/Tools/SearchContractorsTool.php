<?php

declare(strict_types=1);

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
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)->canExecuteTool($user, $this->getName(), $arguments)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
        $query = $arguments['query'] ?? '';
        $limit = max(1, min(30, (int) ($arguments['limit'] ?? 10)));

        $contractors = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->entityQuery($user, (int) $organization->id, 'contractor')
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
