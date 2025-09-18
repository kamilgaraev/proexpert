<?php

namespace App\BusinessModules\Features\ScheduleManagement;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class ScheduleManagementModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Управление расписанием';
    }

    public function getSlug(): string
    {
        return 'schedule-management';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система планирования и управления расписанием работ';
    }

    public function getType(): ModuleType
    {
        return ModuleType::FEATURE;
    }

    public function getBillingModel(): BillingModel
    {
        return BillingModel::SUBSCRIPTION;
    }

    public function getManifest(): array
    {
        return json_decode(file_get_contents(config_path('ModuleList/features/schedule-management.json')), true);
    }

    public function install(): void
    {
        // Платный модуль с продвинутым функционалом планирования
    }

    public function uninstall(): void
    {
        // Платный модуль можно отключить
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        $accessController = app(\App\Modules\Core\AccessController::class);
        return $accessController->hasModuleAccess($organizationId, 'organizations') &&
               $accessController->hasModuleAccess($organizationId, 'users') &&
               $accessController->hasModuleAccess($organizationId, 'project-management');
    }

    public function getDependencies(): array
    {
        return ['organizations', 'users', 'project-management'];
    }

    public function getConflicts(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [
            'schedule.view',
            'schedule.create',
            'schedule.edit',
            'schedule.delete',
            'schedule.assign',
            'schedule.approve',
            'schedule.export',
            'schedule.notifications'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Планирование рабочего расписания',
            'Назначение задач на временные слоты',
            'Управление сменами и графиками',
            'Отслеживание выполнения расписания',
            'Уведомления о изменениях',
            'Экспорт расписания в различные форматы',
            'Интеграция с календарными системами',
            'Автоматическое планирование ресурсов',
            'Контроль конфликтов в расписании',
            'Статистика по выполнению планов'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_schedule_entries_per_month' => 2000,
            'max_concurrent_assignments' => 100,
            'planning_horizon_days' => 365
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'schedule_settings' => [
                'default_work_hours_start' => '08:00',
                'default_work_hours_end' => '17:00',
                'enable_overtime_tracking' => true,
                'auto_assign_resources' => false,
                'conflict_detection_enabled' => true
            ],
            'notification_settings' => [
                'notify_on_schedule_changes' => true,
                'notify_before_task_start' => true,
                'notification_advance_hours' => 2,
                'email_notifications' => true,
                'sms_notifications' => false
            ],
            'planning_settings' => [
                'planning_horizon_days' => 365,
                'allow_past_date_scheduling' => false,
                'auto_reschedule_conflicts' => false,
                'resource_overallocation_warning' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['planning_settings']['planning_horizon_days']) && 
            (!is_int($settings['planning_settings']['planning_horizon_days']) || 
             $settings['planning_settings']['planning_horizon_days'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля управления расписанием');
        }

        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if ($activation) {
            $currentSettings = $activation->module_settings ?? [];
            $activation->update([
                'module_settings' => array_merge($currentSettings, $settings)
            ]);
        }
    }

    public function getSettings(int $organizationId): array
    {
        $activation = \App\Models\OrganizationModuleActivation::where('organization_id', $organizationId)
            ->whereHas('module', function ($query) {
                $query->where('slug', $this->getSlug());
            })
            ->first();

        if (!$activation) {
            return $this->getDefaultSettings();
        }

        return array_merge(
            $this->getDefaultSettings(),
            $activation->module_settings ?? []
        );
    }
}
