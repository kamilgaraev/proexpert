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
        return 'Отправляет уведомление участникам проекта. Если пользователь просит отправить уведомление "мне", "себе" или "напиши мне", ОБЯЗАТЕЛЬНО используйте параметр send_to_me: true.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта (обязателен для привязки уведомления к контексту)'
                ],
                'recipient_role' => [
                    'type' => 'string',
                    'description' => 'Роль в проекте (pm, accountant, owner, worker).',
                    'enum' => ['pm', 'accountant', 'owner', 'worker']
                ],
                'recipient_user_id' => [
                    'type' => 'integer',
                    'description' => 'ID конкретного пользователя из базы (можно найти через search_users).'
                ],
                'send_to_me' => [
                    'type' => 'boolean',
                    'description' => 'Установите true, если пользователь просит уведомление для себя ("мне", "себе").'
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
        $recipientUserId = $arguments['recipient_user_id'] ?? null;
        $sendToMe = $arguments['send_to_me'] ?? false;

        $project = Project::where('id', $projectId)
            ->where('organization_id', $organization->id)
            ->first();

        if (!$project) {
            return [
                'status' => 'error',
                'message' => "Проект с ID {$projectId} не найден."
            ];
        }

        $recipients = collect();

        if ($sendToMe && $user) {
            $recipients->push($user);
        } elseif ($recipientUserId) {
            $targetUser = User::where('id', $recipientUserId)
                ->where('organization_id', $organization->id)
                ->first();
            if ($targetUser) {
                $recipients->push($targetUser);
            } else {
                return ['status' => 'error', 'message' => "Пользователь с ID {$recipientUserId} не найден в вашей организации."];
            }
        } else {
            $query = $project->users();
            if ($role) {
                $query->wherePivot('role', $role);
            }
            $recipients = $query->get();
        }

        if ($recipients->isEmpty()) {
            // Фаллбек: если не указана роль и конкретный пользователь, но в проекте никого нет, 
            // отправим текущему пользователю, если он относится к этой организации.
            if (!$role && !$recipientUserId && $user && ($user->organization_id == $organization->id || $user->current_organization_id == $organization->id)) {
                $recipients->push($user);
            }
        }

        if ($recipients->isEmpty()) {
            return [
                'status' => 'warning',
                'message' => $role 
                    ? "Участники с ролью '{$role}' в проекте не найдены."
                    : "В проекте нет зарегистрированных участников (и вы не указаны как участник)."
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
