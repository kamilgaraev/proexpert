<?php

declare(strict_types=1);

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
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)->canExecuteTool($user, $this->getName(), $arguments)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
        }
        $query = $arguments['query'] ?? '';

        // В этом проекте склады хранятся в organization_warehouses
        $warehouses = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->entityQuery($user, (int) $organization->id, 'warehouse')
            ->where('name', 'ilike', "%{$query}%")
            ->limit(max(1, min(30, (int) ($arguments['limit'] ?? 10))))
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
