<?php

namespace App\BusinessModules\Features\SiteRequests\Services;

use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestStatus;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestStatusTransition;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use Illuminate\Support\Collection;

/**
 * Сервис управления workflow статусов заявок
 */
class SiteRequestWorkflowService
{
    /**
     * Проверить возможность перехода в статус
     */
    public function canTransition(SiteRequest $request, string $toStatus): bool
    {
        $fromStatus = $request->status->value;

        // Сначала проверяем кастомные переходы организации
        $customTransition = $this->getCustomTransition(
            $request->organization_id,
            $fromStatus,
            $toStatus
        );

        if ($customTransition) {
            return $customTransition->is_active;
        }

        // Если нет кастомных, используем базовые переходы
        $defaultTransitions = SiteRequestStatusEnum::getDefaultTransitions();

        return isset($defaultTransitions[$fromStatus]) &&
               in_array($toStatus, $defaultTransitions[$fromStatus]);
    }

    /**
     * Получить доступные переходы из текущего статуса
     */
    public function getAvailableTransitions(SiteRequest $request): array
    {
        $fromStatus = $request->status->value;

        // Получаем кастомные переходы
        $customTransitions = $this->getCustomTransitionsFrom($request->organization_id, $fromStatus);

        if ($customTransitions->isNotEmpty()) {
            return $customTransitions->map(function ($transition) {
                return [
                    'status' => $transition->toStatus->slug,
                    'name' => $transition->toStatus->name,
                    'color' => $transition->toStatus->color,
                    'icon' => $transition->toStatus->icon,
                    'required_permission' => $transition->required_permission,
                ];
            })->values()->toArray();
        }

        // Базовые переходы
        $defaultTransitions = SiteRequestStatusEnum::getDefaultTransitions();
        $availableStatuses = $defaultTransitions[$fromStatus] ?? [];

        return array_map(function ($status) {
            $enum = SiteRequestStatusEnum::from($status);
            return [
                'status' => $status,
                'name' => $enum->label(),
                'color' => $enum->color(),
                'icon' => $enum->icon(),
                'required_permission' => null,
            ];
        }, $availableStatuses);
    }

    /**
     * Валидировать переход статуса
     */
    public function validateStatusTransition(
        string $fromStatus,
        string $toStatus,
        int $organizationId
    ): void {
        // Проверяем кастомные переходы
        $customTransition = $this->getCustomTransition($organizationId, $fromStatus, $toStatus);

        if ($customTransition) {
            if (!$customTransition->is_active) {
                throw new \DomainException("Переход '{$fromStatus}' → '{$toStatus}' отключен");
            }
            return;
        }

        // Проверяем базовые переходы
        $defaultTransitions = SiteRequestStatusEnum::getDefaultTransitions();

        if (!isset($defaultTransitions[$fromStatus]) ||
            !in_array($toStatus, $defaultTransitions[$fromStatus])) {
            throw new \DomainException("Недопустимый переход статуса '{$fromStatus}' → '{$toStatus}'");
        }
    }

    /**
     * Получить требуемое разрешение для перехода
     */
    public function getRequiredPermission(
        int $organizationId,
        string $fromStatus,
        string $toStatus
    ): ?string {
        $transition = $this->getCustomTransition($organizationId, $fromStatus, $toStatus);

        return $transition?->required_permission;
    }

    /**
     * Создать базовые статусы для организации
     */
    public function createDefaultStatuses(int $organizationId): void
    {
        $statuses = [
            ['slug' => 'draft', 'name' => 'Черновик', 'color' => '#9E9E9E', 'icon' => 'file-alt', 'is_initial' => true, 'is_final' => false, 'display_order' => 1],
            ['slug' => 'pending', 'name' => 'Ожидает обработки', 'color' => '#FF9800', 'icon' => 'clock', 'is_initial' => false, 'is_final' => false, 'display_order' => 2],
            ['slug' => 'in_review', 'name' => 'На рассмотрении', 'color' => '#2196F3', 'icon' => 'search', 'is_initial' => false, 'is_final' => false, 'display_order' => 3],
            ['slug' => 'approved', 'name' => 'Одобрена', 'color' => '#4CAF50', 'icon' => 'check-circle', 'is_initial' => false, 'is_final' => false, 'display_order' => 4],
            ['slug' => 'rejected', 'name' => 'Отклонена', 'color' => '#F44336', 'icon' => 'times-circle', 'is_initial' => false, 'is_final' => true, 'display_order' => 5],
            ['slug' => 'in_progress', 'name' => 'В исполнении', 'color' => '#03A9F4', 'icon' => 'spinner', 'is_initial' => false, 'is_final' => false, 'display_order' => 6],
            ['slug' => 'fulfilled', 'name' => 'Выполнена', 'color' => '#8BC34A', 'icon' => 'check-double', 'is_initial' => false, 'is_final' => false, 'display_order' => 7],
            ['slug' => 'completed', 'name' => 'Закрыта', 'color' => '#4CAF50', 'icon' => 'flag-checkered', 'is_initial' => false, 'is_final' => true, 'display_order' => 8],
            ['slug' => 'cancelled', 'name' => 'Отменена', 'color' => '#795548', 'icon' => 'ban', 'is_initial' => false, 'is_final' => true, 'display_order' => 9],
            ['slug' => 'on_hold', 'name' => 'Приостановлена', 'color' => '#607D8B', 'icon' => 'pause-circle', 'is_initial' => false, 'is_final' => false, 'display_order' => 10],
        ];

        foreach ($statuses as $statusData) {
            SiteRequestStatus::firstOrCreate(
                [
                    'organization_id' => $organizationId,
                    'slug' => $statusData['slug'],
                ],
                $statusData
            );
        }

        // Создаем переходы
        $this->createDefaultTransitions($organizationId);
    }

    /**
     * Создать базовые переходы для организации
     */
    private function createDefaultTransitions(int $organizationId): void
    {
        $statuses = SiteRequestStatus::forOrganization($organizationId)->get()->keyBy('slug');

        $transitions = [
            ['from' => 'draft', 'to' => 'pending', 'permission' => null],
            ['from' => 'draft', 'to' => 'cancelled', 'permission' => null],
            ['from' => 'pending', 'to' => 'in_review', 'permission' => 'site_requests.change_status'],
            ['from' => 'pending', 'to' => 'rejected', 'permission' => 'site_requests.change_status'],
            ['from' => 'pending', 'to' => 'cancelled', 'permission' => null],
            ['from' => 'in_review', 'to' => 'approved', 'permission' => 'site_requests.approve'],
            ['from' => 'in_review', 'to' => 'rejected', 'permission' => 'site_requests.approve'],
            ['from' => 'in_review', 'to' => 'pending', 'permission' => 'site_requests.change_status'],
            ['from' => 'approved', 'to' => 'in_progress', 'permission' => 'site_requests.change_status'],
            ['from' => 'approved', 'to' => 'cancelled', 'permission' => 'site_requests.change_status'],
            ['from' => 'in_progress', 'to' => 'fulfilled', 'permission' => 'site_requests.change_status'],
            ['from' => 'in_progress', 'to' => 'on_hold', 'permission' => 'site_requests.change_status'],
            ['from' => 'in_progress', 'to' => 'cancelled', 'permission' => 'site_requests.change_status'],
            ['from' => 'fulfilled', 'to' => 'completed', 'permission' => null],
            ['from' => 'fulfilled', 'to' => 'in_progress', 'permission' => 'site_requests.change_status'],
            ['from' => 'on_hold', 'to' => 'in_progress', 'permission' => 'site_requests.change_status'],
            ['from' => 'on_hold', 'to' => 'cancelled', 'permission' => 'site_requests.change_status'],
        ];

        foreach ($transitions as $transitionData) {
            $fromStatus = $statuses[$transitionData['from']] ?? null;
            $toStatus = $statuses[$transitionData['to']] ?? null;

            if ($fromStatus && $toStatus) {
                SiteRequestStatusTransition::firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'from_status_id' => $fromStatus->id,
                        'to_status_id' => $toStatus->id,
                    ],
                    [
                        'required_permission' => $transitionData['permission'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    /**
     * Получить кастомный переход
     */
    private function getCustomTransition(
        int $organizationId,
        string $fromStatus,
        string $toStatus
    ): ?SiteRequestStatusTransition {
        $fromStatusModel = SiteRequestStatus::forOrganization($organizationId)
            ->where('slug', $fromStatus)
            ->first();

        $toStatusModel = SiteRequestStatus::forOrganization($organizationId)
            ->where('slug', $toStatus)
            ->first();

        if (!$fromStatusModel || !$toStatusModel) {
            return null;
        }

        return SiteRequestStatusTransition::forOrganization($organizationId)
            ->fromStatus($fromStatusModel->id)
            ->toStatus($toStatusModel->id)
            ->first();
    }

    /**
     * Получить все кастомные переходы из статуса
     */
    private function getCustomTransitionsFrom(int $organizationId, string $fromStatus): Collection
    {
        $fromStatusModel = SiteRequestStatus::forOrganization($organizationId)
            ->where('slug', $fromStatus)
            ->first();

        if (!$fromStatusModel) {
            return collect();
        }

        return SiteRequestStatusTransition::forOrganization($organizationId)
            ->fromStatus($fromStatusModel->id)
            ->active()
            ->with('toStatus')
            ->get();
    }
}

