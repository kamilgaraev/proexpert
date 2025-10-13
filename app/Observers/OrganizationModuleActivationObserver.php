<?php

namespace App\Observers;

use App\Models\OrganizationModuleActivation;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Cache;

class OrganizationModuleActivationObserver
{
    /**
     * Обработка события "created" для OrganizationModuleActivation.
     */
    public function created(OrganizationModuleActivation $activation): void
    {
        $this->clearModuleCache($activation);
    }

    /**
     * Обработка события "updated" для OrganizationModuleActivation.
     */
    public function updated(OrganizationModuleActivation $activation): void
    {
        $this->clearModuleCache($activation);
    }

    /**
     * Обработка события "deleted" для OrganizationModuleActivation.
     */
    public function deleted(OrganizationModuleActivation $activation): void
    {
        $this->clearModuleCache($activation);
    }

    /**
     * Очистить кеш доступа к модулям
     */
    protected function clearModuleCache(OrganizationModuleActivation $activation): void
    {
        $organizationId = $activation->organization_id;
        
        // Получаем slug модуля
        $moduleSlug = $activation->module?->slug;
        
        // Очищаем конкретные ключи кеша
        if ($moduleSlug) {
            Cache::forget("org_module_access_{$organizationId}_{$moduleSlug}");
        }
        
        // Очищаем общие ключи организации
        Cache::forget("org_active_modules_{$organizationId}");
        Cache::forget("active_modules_{$organizationId}");
        Cache::forget("modules_with_status_{$organizationId}");
        
        // Очищаем кеш всех разрешений организации
        $this->clearOrganizationPermissionsCache($organizationId);
        
        // Используем AccessController для дополнительной очистки
        try {
            $accessController = app(AccessController::class);
            $accessController->clearAccessCache($organizationId);
        } catch (\Exception $e) {
            // Если не удалось через AccessController, продолжаем
        }
    }

    /**
     * Очистить кеш разрешений всех пользователей организации
     */
    protected function clearOrganizationPermissionsCache(int $organizationId): void
    {
        try {
            $organization = \App\Models\Organization::find($organizationId);
            
            if ($organization) {
                $userIds = $organization->users()->pluck('users.id');
                
                foreach ($userIds as $userId) {
                    Cache::forget("user_permissions_{$userId}_{$organizationId}");
                    Cache::forget("user_permissions_full_{$userId}_{$organizationId}");
                    Cache::forget("user_available_permissions_{$userId}_{$organizationId}");
                }
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки очистки кеша
        }
    }
}

