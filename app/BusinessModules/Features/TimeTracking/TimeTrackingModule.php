<?php

namespace App\BusinessModules\Features\TimeTracking;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Contracts\ConfigurableInterface;
use App\Enums\ModuleType;
use App\Enums\BillingModel;

class TimeTrackingModule implements ModuleInterface, ConfigurableInterface
{
    public function getName(): string
    {
        return 'Учет рабочего времени';
    }

    public function getSlug(): string
    {
        return 'time-tracking';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDescription(): string
    {
        return 'Система учета рабочего времени и трудозатрат';
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
        return json_decode(file_get_contents(config_path('ModuleList/features/time-tracking.json')), true);
    }

    public function install(): void
    {
        // Модуль использует существующие таблицы учета времени
    }

    public function uninstall(): void
    {
        // Платный модуль можно отключить, данные сохраняются
    }

    public function upgrade(string $fromVersion): void
    {
        // Логика обновления модуля
    }

    public function canActivate(int $organizationId): bool
    {
        // Проверяем что необходимые модули активированы
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
            'time_tracking.view',
            'time_tracking.create',
            'time_tracking.edit',
            'time_tracking.delete',
            'time_tracking.approve',
            'time_tracking.reject',
            'time_tracking.submit',
            'time_tracking.statistics',
            'time_tracking.calendar',
            'time_tracking.reports'
        ];
    }

    public function getFeatures(): array
    {
        return [
            'Учет рабочего времени сотрудников',
            'Создание и редактирование записей времени',
            'Система одобрения временных отчетов',
            'Отклонение некорректных записей',
            'Подача отчетов на утверждение',
            'Статистика по рабочему времени',
            'Календарное представление записей',
            'Генерация отчетов по времени',
            'Интеграция с проектами',
            'Контроль переработок'
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_entries_per_month' => 2000,
            'max_hours_per_day' => 24,
            'max_projects_tracked' => 50,
            'report_retention_days' => 365
        ];
    }

    public function getDefaultSettings(): array
    {
        return [
            'tracking_settings' => [
                'allow_future_entries' => false,
                'max_daily_hours' => 12,
                'min_entry_duration_minutes' => 15,
                'require_task_description' => true,
                'auto_break_detection' => true,
                'round_to_nearest_minutes' => 15
            ],
            'approval_settings' => [
                'require_manager_approval' => true,
                'auto_approve_under_hours' => 8,
                'approval_deadline_days' => 7,
                'allow_self_approval' => false,
                'notify_overdue_approvals' => true
            ],
            'reporting_settings' => [
                'generate_weekly_reports' => true,
                'include_project_breakdown' => true,
                'show_overtime_separately' => true,
                'export_format' => 'excel',
                'email_reports_to_managers' => true
            ],
            'notification_settings' => [
                'remind_daily_entries' => true,
                'reminder_time' => '17:00',
                'notify_submission_required' => true,
                'notify_approval_status' => true,
                'email_notifications' => true
            ],
            'calendar_settings' => [
                'working_days' => [1, 2, 3, 4, 5],
                'working_hours_start' => '09:00',
                'working_hours_end' => '18:00',
                'show_weekends' => false,
                'highlight_overtime' => true
            ]
        ];
    }

    public function validateSettings(array $settings): bool
    {
        if (isset($settings['tracking_settings']['max_daily_hours']) && 
            (!is_numeric($settings['tracking_settings']['max_daily_hours']) || 
             $settings['tracking_settings']['max_daily_hours'] < 1 || 
             $settings['tracking_settings']['max_daily_hours'] > 24)) {
            return false;
        }

        if (isset($settings['tracking_settings']['min_entry_duration_minutes']) && 
            (!is_int($settings['tracking_settings']['min_entry_duration_minutes']) || 
             $settings['tracking_settings']['min_entry_duration_minutes'] < 5)) {
            return false;
        }

        if (isset($settings['approval_settings']['approval_deadline_days']) && 
            (!is_int($settings['approval_settings']['approval_deadline_days']) || 
             $settings['approval_settings']['approval_deadline_days'] < 1)) {
            return false;
        }

        return true;
    }

    public function applySettings(int $organizationId, array $settings): void
    {
        if (!$this->validateSettings($settings)) {
            throw new \InvalidArgumentException('Некорректные настройки модуля учета времени');
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
