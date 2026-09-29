<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\User;
use App\Models\Organization;

class SearchUsersTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'search_users';
    }

    public function getDescription(): string
    {
        return 'Ищет сотрудников организации по имени или email. Позволяет найти ID пользователя для отправки персональных уведомлений.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Имя, фамилия или email сотрудника'
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество результатов',
                    'default' => 5
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
        $limit = max(1, min(30, (int) ($arguments['limit'] ?? 5)));

        // Ищем в рамках организации (предполагаем наличие связи или фильтра по организации)
        $users = app(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class)->entityQuery($user, (int) $organization->id, 'user')
            ->where(function($q) use ($query) {
                $q->where('name', 'ilike', "%{$query}%")
                  ->orWhere('email', 'ilike', "%{$query}%");
            })
            ->limit($limit)
            ->get();

        if ($users->isEmpty()) {
            return [
                'status' => 'success',
                'message' => 'Сотрудники не найдены по запросу: ' . $query,
                'results' => []
            ];
        }

        return [
            'status' => 'success',
            'results' => $users->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'job_title' => $u->job_title ?? 'сотрудник',
            ])->toArray()
        ];
    }
}
