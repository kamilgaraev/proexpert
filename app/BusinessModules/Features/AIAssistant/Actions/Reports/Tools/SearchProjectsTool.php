<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
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
        return 'Ищет доступные проекты по названию или адресу. Для текущего выбранного проекта использует project_id из проверенного рабочего контекста и возвращает его актуальные ID и название.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Название проекта или часть адреса',
                ],
                'project_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'ID выбранного проекта из проверенного рабочего контекста. При наличии поиск выполняется по этому ID вместо текстовой фразы.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество результатов (по умолчанию 5)',
                    'default' => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if ($user === null || (int) $user->current_organization_id !== (int) $organization->id
            || ! app(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class)->canExecuteTool($user, $this->getName(), $arguments)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
        }
        $query = $arguments['query'] ?? '';
        $limit = max(1, min(30, (int) ($arguments['limit'] ?? 5)));

        $projects = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->entityQuery($user, (int) $organization->id, 'project');
        if (isset($arguments['project_id'])) {
            $projects->whereKey((int) $arguments['project_id']);
        } else {
            $projects->where(function ($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                    ->orWhere('address', 'ilike', "%{$query}%");
            });
        }
        $projects = $projects->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $projects->count() > $limit;
        if ($hasMore) {
            $projects = $projects->take($limit);
        }
        $resultWindow = ['limit' => $limit, 'returned' => $projects->count(), 'has_more' => $hasMore];

        if ($projects->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'Проекты не найдены по запросу: '.$query,
                'results' => [],
                'result_window' => $resultWindow,
            ];
        }

        return [
            'status' => 'success',
            'results' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'address' => $p->address,
                'status' => $p->status,
            ])->toArray(),
            'result_window' => $resultWindow,
        ];
    }
}
