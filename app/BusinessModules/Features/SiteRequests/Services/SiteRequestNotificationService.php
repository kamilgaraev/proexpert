<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Сервис уведомлений для заявок
 */
class SiteRequestNotificationService
{
    public function __construct(
        private readonly SiteRequestsModule $module
    ) {}

    /**
     * Уведомление о создании заявки
     */
    public function notifyOnCreated(SiteRequest $request): void
    {
        $settings = $this->module->getSettings($request->organization_id);

        if (!$settings['notify_on_create']) {
            return;
        }

        // Получаем менеджеров организации
        $managers = $this->getOrganizationManagers($request->organization_id);

        foreach ($managers as $manager) {
            $this->sendNotification(
                $manager,
                'Новая заявка с объекта',
                "Создана новая заявка: {$request->title}",
                [
                    'type' => 'site_request_created',
                    'request_id' => $request->id,
                    'request_type' => $request->request_type->value,
                    'project_id' => $request->project_id,
                ]
            );
        }

        Log::info('site_request.notification.created', [
            'request_id' => $request->id,
            'managers_notified' => $managers->count(),
        ]);
    }

    /**
     * Уведомление о смене статуса
     */
    public function notifyOnStatusChange(
        SiteRequest $request,
        string $oldStatus,
        string $newStatus,
        int $changedByUserId
    ): void {
        $settings = $this->module->getSettings($request->organization_id);

        if (!$settings['notify_on_status_change']) {
            return;
        }

        // Уведомляем создателя заявки
        $creator = $request->user;
        if ($creator && $creator->id !== $changedByUserId) {
            $this->sendNotification(
                $creator,
                'Статус заявки изменен',
                "Заявка \"{$request->title}\" изменила статус: {$request->status->label()}",
                [
                    'type' => 'site_request_status_changed',
                    'request_id' => $request->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]
            );
        }

        // Уведомляем исполнителя
        if ($request->assignedUser && $request->assigned_to !== $changedByUserId) {
            $this->sendNotification(
                $request->assignedUser,
                'Статус заявки изменен',
                "Заявка \"{$request->title}\" изменила статус: {$request->status->label()}",
                [
                    'type' => 'site_request_status_changed',
                    'request_id' => $request->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]
            );
        }

        Log::info('site_request.notification.status_changed', [
            'request_id' => $request->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
    }

    /**
     * Уведомление о назначении исполнителя
     */
    public function notifyOnAssigned(SiteRequest $request, int $assigneeId, int $assignedByUserId): void
    {
        $settings = $this->module->getSettings($request->organization_id);

        if (!$settings['notify_on_assign']) {
            return;
        }

        $assignee = User::find($assigneeId);

        if ($assignee && $assigneeId !== $assignedByUserId) {
            $this->sendNotification(
                $assignee,
                'Вам назначена заявка',
                "Вам назначена заявка: {$request->title}",
                [
                    'type' => 'site_request_assigned',
                    'request_id' => $request->id,
                    'project_id' => $request->project_id,
                ]
            );
        }

        Log::info('site_request.notification.assigned', [
            'request_id' => $request->id,
            'assignee_id' => $assigneeId,
        ]);
    }

    /**
     * Уведомление о просроченной заявке
     */
    public function notifyOnOverdue(SiteRequest $request): void
    {
        $settings = $this->module->getSettings($request->organization_id);

        if (!$settings['notify_on_overdue']) {
            return;
        }

        // Уведомляем создателя
        if ($request->user) {
            $this->sendNotification(
                $request->user,
                'Заявка просрочена',
                "Заявка \"{$request->title}\" просрочена. Желаемая дата: {$request->required_date->format('d.m.Y')}",
                [
                    'type' => 'site_request_overdue',
                    'request_id' => $request->id,
                    'required_date' => $request->required_date->toDateString(),
                ]
            );
        }

        // Уведомляем исполнителя
        if ($request->assignedUser) {
            $this->sendNotification(
                $request->assignedUser,
                'Заявка просрочена',
                "Заявка \"{$request->title}\" просрочена. Желаемая дата: {$request->required_date->format('d.m.Y')}",
                [
                    'type' => 'site_request_overdue',
                    'request_id' => $request->id,
                    'required_date' => $request->required_date->toDateString(),
                ]
            );
        }

        Log::info('site_request.notification.overdue', [
            'request_id' => $request->id,
        ]);
    }

    /**
     * Отправить уведомление пользователю
     */
    private function sendNotification(
        User $user,
        string $title,
        string $message,
        array $data = []
    ): void {
        // Проверяем наличие модуля notifications
        if ($this->module->hasNotifications($user->current_organization_id ?? 0)) {
            try {
                // Используем модуль notifications
                $notificationService = app(\App\BusinessModules\Features\Notifications\Services\NotificationService::class);
                $notificationService->send($user, $title, $message, $data);
            } catch (\Exception $e) {
                Log::warning('site_request.notification.failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Fallback: используем стандартные уведомления Laravel
            // Здесь можно добавить отправку email или других уведомлений
            Log::info('site_request.notification.fallback', [
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
            ]);
        }
    }

    /**
     * Получить менеджеров организации (владельцы и админы по новой системе авторизации).
     */
    private function getOrganizationManagers(int $organizationId): \Illuminate\Support\Collection
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        if (!$context) {
            return collect();
        }

        return User::whereHas('organizations', function ($query) use ($organizationId) {
            $query->where('organizations.id', $organizationId);
        })
            ->whereHas('roleAssignments', function ($query) use ($context) {
                $query->active()
                    ->where('context_id', $context->id)
                    ->whereIn('role_slug', ['organization_owner', 'organization_admin']);
            })
            ->get();
    }
}

