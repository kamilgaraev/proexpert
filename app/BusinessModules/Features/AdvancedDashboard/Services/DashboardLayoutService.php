<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\BusinessModules\Features\AdvancedDashboard\Models\Dashboard;
use App\Models\User;

/**
 * Сервис управления layout дашбордов
 * 
 * Предоставляет методы для:
 * - Создания и управления дашбордами
 * - Обновления layout и виджетов
 * - Расшаривания дашбордов
 * - Работы с шаблонами
 */
class DashboardLayoutService
{
    /**
     * Создать новый дашборд
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param array $data Данные дашборда
     * @return Dashboard
     */
    public function createDashboard(int $userId, int $organizationId, array $data): Dashboard
    {
        // Проверяем лимит дашбордов на пользователя
        $this->checkDashboardLimit($userId, $organizationId);
        
        // Генерируем slug если не указан
        $slug = $data['slug'] ?? Str::slug($data['name'] . '-' . uniqid());
        
        // Создаем дашборд
        $dashboard = Dashboard::create([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'slug' => $slug,
            'layout' => $data['layout'] ?? $this->getDefaultLayout(),
            'widgets' => $data['widgets'] ?? [],
            'filters' => $data['filters'] ?? [],
            'template' => $data['template'] ?? 'custom',
            'refresh_interval' => $data['refresh_interval'] ?? 300,
            'enable_realtime' => $data['enable_realtime'] ?? false,
            'visibility' => $data['visibility'] ?? 'private',
        ]);
        
        // Если это первый дашборд, делаем его дефолтным
        if ($this->getUserDashboardsCount($userId, $organizationId) === 1) {
            $dashboard->makeDefault();
        }
        
        // Очищаем кеш
        $this->clearUserDashboardCache($userId, $organizationId);
        
        return $dashboard;
    }

    /**
     * Создать дашборд из шаблона
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param string $template Тип шаблона (admin, finance, technical)
     * @param string|null $name Название (если null, используется дефолтное)
     * @return Dashboard
     */
    public function createFromTemplate(int $userId, int $organizationId, string $template, ?string $name = null): Dashboard
    {
        $templateConfig = $this->getTemplateConfig($template);
        
        if (!$templateConfig) {
            throw new \InvalidArgumentException("Template '{$template}' not found");
        }
        
        $data = [
            'name' => $name ?? $templateConfig['name'],
            'description' => $templateConfig['description'],
            'template' => $template,
            'layout' => $templateConfig['layout'],
            'widgets' => $templateConfig['widgets'],
            'filters' => $templateConfig['filters'] ?? [],
        ];
        
        return $this->createDashboard($userId, $organizationId, $data);
    }

    /**
     * Обновить layout дашборда
     * 
     * @param int $dashboardId ID дашборда
     * @param array $layout Новый layout
     * @return Dashboard
     */
    public function updateDashboardLayout(int $dashboardId, array $layout): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        $dashboard->update(['layout' => $layout]);
        
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        return $dashboard->fresh();
    }

    /**
     * Обновить виджеты дашборда
     * 
     * @param int $dashboardId ID дашборда
     * @param array $widgets Массив виджетов
     * @return Dashboard
     */
    public function updateDashboardWidgets(int $dashboardId, array $widgets): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        $dashboard->update(['widgets' => $widgets]);
        
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        return $dashboard->fresh();
    }

    /**
     * Обновить фильтры дашборда
     * 
     * @param int $dashboardId ID дашборда
     * @param array $filters Глобальные фильтры
     * @return Dashboard
     */
    public function updateDashboardFilters(int $dashboardId, array $filters): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        $dashboard->update(['filters' => $filters]);
        
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        return $dashboard->fresh();
    }

    /**
     * Расшарить дашборд с пользователями
     * 
     * @param int $dashboardId ID дашборда
     * @param array $userIds Массив ID пользователей
     * @param string $visibility Уровень видимости (team, organization)
     * @return Dashboard
     */
    public function shareDashboard(int $dashboardId, array $userIds = [], string $visibility = 'team'): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        $dashboard->update([
            'is_shared' => true,
            'shared_with' => $userIds,
            'visibility' => $visibility,
        ]);
        
        // Очищаем кеш владельца
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        // Очищаем кеш для всех пользователей, с которыми расшарили
        foreach ($userIds as $userId) {
            $this->clearUserDashboardCache($userId, $dashboard->organization_id);
        }
        
        return $dashboard->fresh();
    }

    /**
     * Убрать расшаривание дашборда
     * 
     * @param int $dashboardId ID дашборда
     * @return Dashboard
     */
    public function unshareDashboard(int $dashboardId): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        // Сохраняем список пользователей до очистки
        $sharedWithUsers = $dashboard->shared_with ?? [];
        
        $dashboard->update([
            'is_shared' => false,
            'shared_with' => [],
            'visibility' => 'private',
        ]);
        
        // Очищаем кеш владельца
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        // Очищаем кеш для всех пользователей, у которых был доступ
        foreach ($sharedWithUsers as $userId) {
            $this->clearUserDashboardCache($userId, $dashboard->organization_id);
        }
        
        return $dashboard->fresh();
    }

    /**
     * Дублировать дашборд
     * 
     * @param int $dashboardId ID дашборда
     * @param string|null $newName Новое название
     * @return Dashboard
     */
    public function duplicateDashboard(int $dashboardId, ?string $newName = null): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        // Проверяем лимит
        $this->checkDashboardLimit($dashboard->user_id, $dashboard->organization_id);
        
        $newDashboard = $dashboard->duplicate($newName);
        
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        return $newDashboard;
    }

    /**
     * Удалить дашборд
     * 
     * @param int $dashboardId ID дашборда
     * @return bool
     */
    public function deleteDashboard(int $dashboardId): bool
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        $wasDefault = $dashboard->is_default;
        $userId = $dashboard->user_id;
        $organizationId = $dashboard->organization_id;
        
        $dashboard->delete();
        
        // Если был дефолтным, назначаем другой дашборд дефолтным
        if ($wasDefault) {
            $this->assignDefaultDashboard($userId, $organizationId);
        }
        
        $this->clearUserDashboardCache($userId, $organizationId);
        
        return true;
    }

    /**
     * Получить все дашборды пользователя
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @param bool $includeShared Включить расшаренные дашборды
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUserDashboards(int $userId, int $organizationId, bool $includeShared = true)
    {
        $cacheKey = "user_dashboards_{$userId}_{$organizationId}_{$includeShared}";
        $tags = $this->getDashboardCacheTags($userId, $organizationId);
        
        if ($this->supportsTaggedCache()) {
            return Cache::tags($tags)->remember($cacheKey, 60, function () use ($userId, $organizationId, $includeShared) {
                return $this->fetchUserDashboards($userId, $organizationId, $includeShared);
            });
        }
        
        return Cache::remember($cacheKey, 60, function () use ($userId, $organizationId, $includeShared) {
            return $this->fetchUserDashboards($userId, $organizationId, $includeShared);
        });
    }

    /**
     * Получить дефолтный дашборд пользователя
     * 
     * @param int $userId ID пользователя
     * @param int $organizationId ID организации
     * @return Dashboard|null
     */
    public function getDefaultDashboard(int $userId, int $organizationId): ?Dashboard
    {
        $cacheKey = "default_dashboard_{$userId}_{$organizationId}";
        $tags = $this->getDashboardCacheTags($userId, $organizationId);
        
        if ($this->supportsTaggedCache()) {
            return Cache::tags($tags)->remember($cacheKey, 60, function () use ($userId, $organizationId) {
                return Dashboard::forUser($userId)
                    ->forOrganization($organizationId)
                    ->default()
                    ->first();
            });
        }
        
        return Cache::remember($cacheKey, 60, function () use ($userId, $organizationId) {
            return Dashboard::forUser($userId)
                ->forOrganization($organizationId)
                ->default()
                ->first();
        });
    }

    /**
     * Установить дашборд как дефолтный
     * 
     * @param int $dashboardId ID дашборда
     * @return Dashboard
     */
    public function setDefaultDashboard(int $dashboardId): Dashboard
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        $dashboard->makeDefault();
        
        $this->clearUserDashboardCache($dashboard->user_id, $dashboard->organization_id);
        
        return $dashboard->fresh();
    }

    /**
     * Получить доступные шаблоны
     * 
     * @return array
     */
    public function getAvailableTemplates(): array
    {
        return [
            'financial' => [
                'name' => 'Финансовая аналитика',
                'description' => 'Финансовые показатели, прогнозы и рентабельность',
                'icon' => 'dollar-sign',
            ],
            'projects' => [
                'name' => 'Проекты',
                'description' => 'Управление и аналитика проектов',
                'icon' => 'folder',
            ],
            'contracts' => [
                'name' => 'Контракты',
                'description' => 'Анализ контрактов и платежей',
                'icon' => 'file-text',
            ],
            'materials' => [
                'name' => 'Материалы',
                'description' => 'Учет и прогнозирование материалов',
                'icon' => 'package',
            ],
            'hr' => [
                'name' => 'HR и KPI',
                'description' => 'Аналитика персонала и эффективности',
                'icon' => 'users',
            ],
            'predictive' => [
                'name' => 'Предиктивная аналитика',
                'description' => 'Прогнозы и анализ рисков',
                'icon' => 'trending-up',
            ],
            'activity' => [
                'name' => 'Активность',
                'description' => 'История действий и события системы',
                'icon' => 'activity',
            ],
            'performance' => [
                'name' => 'Производительность',
                'description' => 'Мониторинг использования системы',
                'icon' => 'zap',
            ],
        ];
    }

    // ==================== PROTECTED HELPER METHODS ====================

    /**
     * Проверить лимит дашбордов на пользователя
     */
    protected function checkDashboardLimit(int $userId, int $organizationId): void
    {
        $count = $this->getUserDashboardsCount($userId, $organizationId);
        $limit = 10; // TODO: Получать из настроек модуля
        
        if ($count >= $limit) {
            throw new \Exception("Dashboard limit reached. Maximum {$limit} dashboards per user.");
        }
    }

    /**
     * Получить количество дашбордов пользователя
     */
    protected function getUserDashboardsCount(int $userId, int $organizationId): int
    {
        return Dashboard::forUser($userId)
            ->forOrganization($organizationId)
            ->count();
    }

    /**
     * Назначить дефолтный дашборд пользователю
     */
    protected function assignDefaultDashboard(int $userId, int $organizationId): void
    {
        $dashboard = Dashboard::forUser($userId)
            ->forOrganization($organizationId)
            ->orderBy('created_at', 'desc')
            ->first();
        
        if ($dashboard) {
            $dashboard->makeDefault();
        }
    }

    /**
     * Очистить кеш дашбордов (публичный метод для контроллеров)
     */
    public function clearDashboardCache(int $userId, int $organizationId): void
    {
        $this->clearUserDashboardCache($userId, $organizationId);
    }

    /**
     * Очистить кеш дашбордов пользователя
     */
    protected function clearUserDashboardCache(int $userId, int $organizationId): void
    {
        if ($this->supportsTaggedCache()) {
            // Используем tagged cache для полной инвалидации
            $tags = $this->getDashboardCacheTags($userId, $organizationId);
            Cache::tags($tags)->flush();
        } else {
            // Fallback для драйверов без поддержки тегов
            Cache::forget("user_dashboards_{$userId}_{$organizationId}_true");
            Cache::forget("user_dashboards_{$userId}_{$organizationId}_false");
            Cache::forget("default_dashboard_{$userId}_{$organizationId}");
        }
    }

    /**
     * Проверить поддержку tagged cache
     */
    protected function supportsTaggedCache(): bool
    {
        $driver = config('cache.default');
        return in_array($driver, ['redis', 'memcached']);
    }

    /**
     * Получить теги для кеширования дашбордов
     */
    protected function getDashboardCacheTags(int $userId, int $organizationId): array
    {
        return [
            "dashboards",
            "user:{$userId}",
            "org:{$organizationId}",
        ];
    }

    /**
     * Получить дашборды пользователя из БД
     */
    protected function fetchUserDashboards(int $userId, int $organizationId, bool $includeShared): \Illuminate\Database\Eloquent\Collection
    {
        if ($includeShared) {
            return Dashboard::visible($userId, $organizationId)
                ->orderBy('is_default', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();
        }
        
        return Dashboard::forUser($userId)
            ->forOrganization($organizationId)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Получить дефолтный layout
     */
    protected function getDefaultLayout(): array
    {
        return [
            'type' => 'grid',
            'columns' => 12,
            'rows' => 'auto',
            'gap' => 16,
        ];
    }

    /**
     * Получить конфигурацию шаблона
     */
    protected function getTemplateConfig(string $template): ?array
    {
        $templates = [
            'financial' => [
                'name' => 'Финансовая аналитика',
                'description' => 'Финансовые показатели, прогнозы и рентабельность',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'cash-flow',
                        'type' => 'cash_flow',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'profit-loss',
                        'type' => 'profit_loss',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'roi',
                        'type' => 'roi',
                        'position' => ['x' => 0, 'y' => 3, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'revenue-forecast',
                        'type' => 'revenue_forecast',
                        'position' => ['x' => 6, 'y' => 3, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'financial-health',
                        'type' => 'financial_health',
                        'position' => ['x' => 0, 'y' => 6, 'w' => 6, 'h' => 3],
                    ],
                ],
            ],
            'projects' => [
                'name' => 'Проекты',
                'description' => 'Управление и аналитика проектов',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'projects-overview',
                        'type' => 'projects_overview',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
                    ],
                    [
                        'id' => 'projects-status',
                        'type' => 'projects_status',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 2],
                    ],
                    [
                        'id' => 'projects-timeline',
                        'type' => 'projects_timeline',
                        'position' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 4],
                    ],
                    [
                        'id' => 'projects-budget',
                        'type' => 'projects_budget',
                        'position' => ['x' => 0, 'y' => 6, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'projects-risks',
                        'type' => 'projects_risks',
                        'position' => ['x' => 6, 'y' => 6, 'w' => 6, 'h' => 3],
                    ],
                ],
            ],
            'contracts' => [
                'name' => 'Контракты',
                'description' => 'Анализ контрактов и платежей',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'contracts-overview',
                        'type' => 'contracts_overview',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
                    ],
                    [
                        'id' => 'contracts-status',
                        'type' => 'contracts_status',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 2],
                    ],
                    [
                        'id' => 'contracts-payments',
                        'type' => 'contracts_payments',
                        'position' => ['x' => 0, 'y' => 2, 'w' => 12, 'h' => 3],
                    ],
                    [
                        'id' => 'contracts-performance',
                        'type' => 'contracts_performance',
                        'position' => ['x' => 0, 'y' => 5, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'contracts-upcoming',
                        'type' => 'contracts_upcoming',
                        'position' => ['x' => 6, 'y' => 5, 'w' => 6, 'h' => 3],
                    ],
                ],
            ],
            'materials' => [
                'name' => 'Материалы',
                'description' => 'Учет и прогнозирование материалов',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'materials-inventory',
                        'type' => 'materials_inventory',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'materials-consumption',
                        'type' => 'materials_consumption',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'materials-low-stock',
                        'type' => 'materials_low_stock',
                        'position' => ['x' => 0, 'y' => 3, 'w' => 12, 'h' => 3],
                    ],
                    [
                        'id' => 'materials-forecast',
                        'type' => 'materials_forecast',
                        'position' => ['x' => 0, 'y' => 6, 'w' => 12, 'h' => 4],
                    ],
                ],
            ],
            'hr' => [
                'name' => 'HR и KPI',
                'description' => 'Аналитика персонала и эффективности',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'employee-kpi',
                        'type' => 'employee_kpi',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'top-performers',
                        'type' => 'top_performers',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'resource-utilization',
                        'type' => 'resource_utilization',
                        'position' => ['x' => 0, 'y' => 3, 'w' => 12, 'h' => 4],
                    ],
                    [
                        'id' => 'team-performance',
                        'type' => 'team_performance',
                        'position' => ['x' => 0, 'y' => 7, 'w' => 12, 'h' => 4],
                    ],
                ],
            ],
            'predictive' => [
                'name' => 'Предиктивная аналитика',
                'description' => 'Прогнозы и анализ рисков',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'budget-risk',
                        'type' => 'budget_risk',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'deadline-risk',
                        'type' => 'deadline_risk',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'cash-flow-forecast',
                        'type' => 'cash_flow_forecast',
                        'position' => ['x' => 0, 'y' => 3, 'w' => 12, 'h' => 4],
                    ],
                    [
                        'id' => 'trend-analysis',
                        'type' => 'trend_analysis',
                        'position' => ['x' => 0, 'y' => 7, 'w' => 12, 'h' => 4],
                    ],
                ],
            ],
            'activity' => [
                'name' => 'Активность',
                'description' => 'История действий и события системы',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'recent-activity',
                        'type' => 'recent_activity',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 12, 'h' => 3],
                    ],
                    [
                        'id' => 'system-events',
                        'type' => 'system_events',
                        'position' => ['x' => 0, 'y' => 3, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'user-actions',
                        'type' => 'user_actions',
                        'position' => ['x' => 6, 'y' => 3, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'audit-log',
                        'type' => 'audit_log',
                        'position' => ['x' => 0, 'y' => 6, 'w' => 12, 'h' => 4],
                    ],
                ],
            ],
            'performance' => [
                'name' => 'Производительность',
                'description' => 'Мониторинг использования системы',
                'layout' => $this->getDefaultLayout(),
                'widgets' => [
                    [
                        'id' => 'system-metrics',
                        'type' => 'system_metrics',
                        'position' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'api-performance',
                        'type' => 'api_performance',
                        'position' => ['x' => 6, 'y' => 0, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'database-stats',
                        'type' => 'database_stats',
                        'position' => ['x' => 0, 'y' => 3, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'cache-stats',
                        'type' => 'cache_stats',
                        'position' => ['x' => 6, 'y' => 3, 'w' => 6, 'h' => 3],
                    ],
                    [
                        'id' => 'response-times',
                        'type' => 'response_times',
                        'position' => ['x' => 0, 'y' => 6, 'w' => 12, 'h' => 4],
                    ],
                ],
            ],
        ];
        
        return $templates[$template] ?? null;
    }
}

