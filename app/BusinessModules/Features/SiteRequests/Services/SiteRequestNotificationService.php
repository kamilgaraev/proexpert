<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\SiteRequestsModule;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use function trans_message;

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
        DB::transaction(function () use ($request): void {
            // All publication deliveries lock the request before checking the notification key.
            $lockedRequest = SiteRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedRequest instanceof SiteRequest) {
                $this->notifyOnCreatedLocked($lockedRequest);
            }
        });
    }

    private function notifyOnCreatedLocked(SiteRequest $request): void
    {
        if ($request->status === SiteRequestStatusEnum::DRAFT) {
            return;
        }

        $settings = $this->module->getSettings($request->organization_id);

        if (!$settings['notify_on_create']) {
            return;
        }

        // Получаем менеджеров организации
        $managers = $this->getOrganizationManagers($request->organization_id);
        $publicationKey = "site_request:{$request->id}:submitted";

        foreach ($managers as $manager) {
            if ($this->publicationNotificationExists($manager, $request, $publicationKey)) {
                continue;
            }

            $this->sendNotification(
                $manager,
                trans_message('site_requests.notifications.created.title'),
                trans_message('site_requests.notifications.created.message', ['title' => $request->title]),
                [
                    'type' => 'site_request_created',
                    'organization_id' => $request->organization_id,
                    'request_id' => $request->id,
                    'request_type' => $request->request_type->value,
                    'project_id' => $request->project_id,
                    'publication_key' => $publicationKey,
                ]
            );
        }

        Log::info('site_request.notification.created', [
            'request_id' => $request->id,
            'managers_notified' => $managers->count(),
        ]);
    }

    private function publicationNotificationExists(
        User $user,
        SiteRequest $request,
        string $publicationKey
    ): bool {
        return $this->whereJsonValue(
            Notification::query()
                ->where('type', 'site_request_created')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->where('organization_id', $request->organization_id),
            'publication_key',
            $publicationKey
        )->exists();
    }

    /**
     * Уведомление о смене статуса
     */
    public function notifyOnStatusChange(
        SiteRequest $request,
        string $oldStatus,
        string $newStatus,
        int $changedByUserId,
        string $transitionKey
    ): void {
        DB::transaction(function () use (
            $request,
            $oldStatus,
            $newStatus,
            $changedByUserId,
            $transitionKey
        ): void {
            $lockedRequest = SiteRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedRequest instanceof SiteRequest) {
                $this->notifyOnStatusChangeLocked(
                    $lockedRequest,
                    $oldStatus,
                    $newStatus,
                    $changedByUserId,
                    $transitionKey
                );
            }
        });
    }

    private function notifyOnStatusChangeLocked(
        SiteRequest $request,
        string $oldStatus,
        string $newStatus,
        int $changedByUserId,
        string $transitionKey
    ): void {
        $settings = $this->module->getSettings($request->organization_id);

        if (!$settings['notify_on_status_change']) {
            return;
        }

        // Уведомляем создателя заявки
        $creator = $request->user;
        if (
            $creator
            && $creator->id !== $changedByUserId
            && !$this->statusNotificationExists($creator, $request, $transitionKey)
        ) {
            $this->sendNotification(
                $creator,
                trans_message('site_requests.notifications.status_changed.title'),
                trans_message('site_requests.notifications.status_changed.message', [
                    'title' => $request->title,
                    'status' => $request->status->label(),
                ]),
                [
                    'type' => 'site_request_status_changed',
                    'organization_id' => $request->organization_id,
                    'request_id' => $request->id,
                    'project_id' => $request->project_id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'transition_key' => $transitionKey,
                ]
            );
        }

        // Уведомляем исполнителя
        if (
            $request->assignedUser
            && $request->assigned_to !== $changedByUserId
            && !$this->statusNotificationExists($request->assignedUser, $request, $transitionKey)
        ) {
            $this->sendNotification(
                $request->assignedUser,
                trans_message('site_requests.notifications.status_changed.title'),
                trans_message('site_requests.notifications.status_changed.message', [
                    'title' => $request->title,
                    'status' => $request->status->label(),
                ]),
                [
                    'type' => 'site_request_status_changed',
                    'organization_id' => $request->organization_id,
                    'request_id' => $request->id,
                    'project_id' => $request->project_id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'transition_key' => $transitionKey,
                ]
            );
        }

        Log::info('site_request.notification.status_changed', [
            'request_id' => $request->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
    }

    private function statusNotificationExists(
        User $user,
        SiteRequest $request,
        string $transitionKey
    ): bool {
        return $this->whereJsonValue(
            Notification::query()
                ->where('type', 'site_request_status_changed')
                ->where('notifiable_type', User::class)
                ->where('notifiable_id', $user->id)
                ->where('organization_id', $request->organization_id),
            'transition_key',
            $transitionKey
        )->exists();
    }

    private function whereJsonValue(Builder $query, string $key, string $value): Builder
    {
        return match (DB::getDriverName()) {
            'pgsql' => $query->whereRaw('data::jsonb ->> ? = ?', [$key, $value]),
            'sqlite' => $query->whereRaw('json_extract(data, ?) = ?', ["$.{$key}", $value]),
            default => $query->where("data->{$key}", $value),
        };
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
                trans_message('site_requests.notifications.assigned.title'),
                trans_message('site_requests.notifications.assigned.message', ['title' => $request->title]),
                [
                    'type' => 'site_request_assigned',
                    'organization_id' => $request->organization_id,
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
                trans_message('site_requests.notifications.overdue.title'),
                trans_message('site_requests.notifications.overdue.message', [
                    'title' => $request->title,
                    'date' => $request->required_date->format('d.m.Y'),
                ]),
                [
                    'type' => 'site_request_overdue',
                    'organization_id' => $request->organization_id,
                    'request_id' => $request->id,
                    'project_id' => $request->project_id,
                    'required_date' => $request->required_date->toDateString(),
                ]
            );
        }

        // Уведомляем исполнителя
        if ($request->assignedUser) {
            $this->sendNotification(
                $request->assignedUser,
                trans_message('site_requests.notifications.overdue.title'),
                trans_message('site_requests.notifications.overdue.message', [
                    'title' => $request->title,
                    'date' => $request->required_date->format('d.m.Y'),
                ]),
                [
                    'type' => 'site_request_overdue',
                    'organization_id' => $request->organization_id,
                    'request_id' => $request->id,
                    'project_id' => $request->project_id,
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
        $organizationId = (int) ($data['organization_id'] ?? $user->current_organization_id ?? 0);

        if ($this->module->hasNotifications($organizationId)) {
            try {
                // Используем модуль notifications
                $notificationService = app(NotificationService::class);
                $notificationService->send(
                    $user,
                    (string) ($data['type'] ?? 'site_request'),
                    [
                        ...$data,
                        'title' => $title,
                        'message' => $message,
                        'category' => 'site_requests',
                        'interface' => 'admin',
                        'entity' => [
                            'type' => 'site_request',
                            'id' => $data['request_id'] ?? null,
                        ],
                        'target_route' => isset($data['request_id']) ? "/site-requests/{$data['request_id']}" : '/site-requests',
                    ],
                    'site_requests',
                    'normal',
                    null,
                    $organizationId
                );
            } catch (Throwable $e) {
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

