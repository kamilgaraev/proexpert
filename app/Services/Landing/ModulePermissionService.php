<?php

namespace App\Services\Landing;

use App\Models\User;
use App\Models\OrganizationModule;
use Illuminate\Support\Facades\Cache;

class ModulePermissionService
{
    protected OrganizationModuleService $moduleService;

    public function __construct(OrganizationModuleService $moduleService)
    {
        $this->moduleService = $moduleService;
    }

    public function userHasModulePermission(User $user, string $permission): bool
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return false;
        }

        return $this->moduleService->hasModulePermission($organizationId, $permission);
    }

    public function userHasModuleAccess(User $user, string $moduleSlug): bool
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return false;
        }

        return $this->moduleService->hasModuleAccess($organizationId, $moduleSlug);
    }

    public function getUserAvailableModulePermissions(User $user): array
    {
        $organizationId = $user->current_organization_id;
        
        if (!$organizationId) {
            return [];
        }

        $cacheKey = "user_module_permissions_{$user->id}_{$organizationId}";
        
        return Cache::remember($cacheKey, 300, function () use ($organizationId) {
            $activeModules = $this->moduleService->getOrganizationActiveModules($organizationId);
            
            $permissions = [];
            foreach ($activeModules as $activation) {
                if ($activation->module && $activation->module->permissions) {
                    $permissions = array_merge($permissions, $activation->module->permissions);
                }
            }
            
            return array_unique($permissions);
        });
    }

    public function getModulePermissionGroups(): array
    {
        return [
            'analytics' => [
                'name' => 'Аналитика',
                'permissions' => [
                    'analytics.view' => 'Просмотр аналитики',
                    'analytics.export' => 'Экспорт аналитических данных',
                    'dashboard.advanced' => 'Расширенные дашборды',
                ]
            ],
            'reports' => [
                'name' => 'Отчеты',
                'permissions' => [
                    'reports.basic' => 'Базовые отчеты',
                    'reports.advanced' => 'Расширенные отчеты',
                    'reports.constructor' => 'Конструктор отчетов',
                    'reports.automation' => 'Автоматизация отчетов',
                    'reports.export_pdf' => 'Экспорт в PDF',
                ]
            ],
            'integration' => [
                'name' => 'Интеграции',
                'permissions' => [
                    'integration.1c' => 'Интеграция с 1С',
                    'integration.export' => 'Экспорт данных',
                    'integration.import' => 'Импорт данных',
                    'api.advanced' => 'Расширенный API',
                    'api.webhooks' => 'Веб-хуки',
                    'api.custom' => 'Кастомные эндпоинты',
                ]
            ],
            'automation' => [
                'name' => 'Автоматизация',
                'permissions' => [
                    'automation.processes' => 'Автоматизация процессов',
                    'automation.notifications' => 'Автоматические уведомления',
                    'automation.triggers' => 'Триггеры событий',
                    'notifications.sms' => 'SMS уведомления',
                    'notifications.email' => 'Email уведомления',
                    'notifications.push' => 'Push уведомления',
                ]
            ],
            'customization' => [
                'name' => 'Кастомизация',
                'permissions' => [
                    'branding.logo' => 'Кастомный логотип',
                    'branding.colors' => 'Настройка цветов',
                    'branding.domain' => 'Кастомный домен',
                    'fields.custom' => 'Кастомные поля',
                    'fields.create' => 'Создание полей',
                    'fields.manage' => 'Управление полями',
                ]
            ],
            'security' => [
                'name' => 'Безопасность',
                'permissions' => [
                    'security.2fa' => 'Двухфакторная аутентификация',
                    'security.audit' => 'Аудит действий',
                    'security.ip_restrictions' => 'IP ограничения',
                ]
            ],
            'support' => [
                'name' => 'Поддержка',
                'permissions' => [
                    'support.premium' => 'Премиум поддержка',
                    'support.priority' => 'Приоритетные тикеты',
                    'support.phone' => 'Телефонная поддержка',
                ]
            ],
        ];
    }
} 