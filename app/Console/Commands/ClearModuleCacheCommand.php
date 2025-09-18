<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Organization;

class ClearModuleCacheCommand extends Command
{
    protected $signature = 'modules:clear-cache {--organization-id= : Clear cache for specific organization only}';
    protected $description = 'Clear module access and permission cache';

    public function handle(): int
    {
        $organizationId = $this->option('organization-id');
        
        if ($organizationId) {
            $this->clearOrganizationCache((int)$organizationId);
            $this->info("Cleared module cache for organization {$organizationId}");
        } else {
            $this->clearAllModuleCache();
            $this->info("Cleared all module cache");
        }
        
        return 0;
    }
    
    private function clearOrganizationCache(int $organizationId): void
    {
        $app = app(\App\Modules\Core\AccessController::class);
        $app->clearAccessCache($organizationId);
    }
    
    private function clearAllModuleCache(): void
    {
        // Очищаем весь кэш - самый надежный способ
        Cache::flush();
        
        // Или более точечно
        $organizations = Organization::pluck('id');
        foreach ($organizations as $orgId) {
            $this->clearOrganizationCache($orgId);
        }
        
        // Очищаем кэш реестра модулей
        Cache::forget('modules_registry');
        Cache::forget('modules_last_scan');
    }
}
