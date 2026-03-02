<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Project;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\ProjectNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class SendProjectNotificationTool implements AIToolInterface
{
    public function getName(): string
    {
        return 'send_project_notification';
    }

    public function getDescription(): string
    {
        return 'Отправляет уведомление участникам проекта с определенной ролью (например, менеджеру проекта или бухгалтеру).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта'
                ],
                'recipient_role' => [
                    'type' => 'string',
                    'description' => 'Роль получателя (например: pm, accountant, owner, worker). Если не указано, уведомление будет отправлено всем участникам.',
                    'enum' => ['pm', 'accountant', 'owner', 'worker']
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Текст сообщения'
                ]
            ],
            'required' => ['project_id', 'message']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $projectId = $arguments['project_id'];
        $messageText = $arguments['message'];
        $role = $arguments['recipient_role'] ?? null;

        $project = Project::where('id', $projectId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$project) {
            return [
                'status' => 'error',
                'message' => "Проект с ID {$projectId} не найден."
            ];
        }

        $query = $project->users();
        if ($role) {
            $query->wherePivot('role', $role);
        }

        $recipients = $query->get();

        if ($recipients->isEmpty()) {
            return [
                'status' => 'warning',
                'message' => $role 
                    ? "Участники с ролью '{$role}' в проекте не найдены."
                    : "В проекте нет зарегистрированных участников."
            ];
        }

        try {
            Notification::send($recipients, new ProjectNotification($project, $messageText, $user));

            return [
                'status' => 'success',
                'message' => "Уведомление успешно отправлено " . $recipients->count() . " участникам проекта.",
                'recipients_count' => $recipients->count()
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (SendProjectNotificationTool): ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => 'Ошибка при отправке уведомления: ' . $e->getMessage()
            ];
        }
    }
}
