<?php

namespace App\Observers;

use App\Models\ProjectOrganization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProjectOrganizationObserver
{
    /**
     * Handle the ProjectOrganization "created" event.
     */
    public function created(ProjectOrganization $projectOrganization): void
    {
        $this->invalidateCache($projectOrganization);
        
        Log::info('ProjectOrganization created', [
            'project_id' => $projectOrganization->project_id,
            'organization_id' => $projectOrganization->organization_id,
            'role' => $projectOrganization->role->value,
        ]);
    }

    /**
     * Handle the ProjectOrganization "updated" event.
     */
    public function updated(ProjectOrganization $projectOrganization): void
    {
        $this->invalidateCache($projectOrganization);
        
        // Логируем если изменилась роль
        if ($projectOrganization->isDirty('role_new') || $projectOrganization->isDirty('role')) {
            Log::info('ProjectOrganization role changed', [
                'project_id' => $projectOrganization->project_id,
                'organization_id' => $projectOrganization->organization_id,
                'old_role' => $projectOrganization->getOriginal('role_new') ?? $projectOrganization->getOriginal('role'),
                'new_role' => $projectOrganization->role_new ?? $projectOrganization->role,
            ]);
        }
        
        // Логируем если изменился статус активности
        if ($projectOrganization->isDirty('is_active')) {
            Log::info('ProjectOrganization activity changed', [
                'project_id' => $projectOrganization->project_id,
                'organization_id' => $projectOrganization->organization_id,
                'is_active' => $projectOrganization->is_active,
            ]);
        }
    }

    /**
     * Handle the ProjectOrganization "deleted" event.
     */
    public function deleted(ProjectOrganization $projectOrganization): void
    {
        $this->invalidateCache($projectOrganization);
        
        Log::info('ProjectOrganization deleted', [
            'project_id' => $projectOrganization->project_id,
            'organization_id' => $projectOrganization->organization_id,
        ]);
    }

    /**
     * Handle the ProjectOrganization "restored" event.
     */
    public function restored(ProjectOrganization $projectOrganization): void
    {
        $this->invalidateCache($projectOrganization);
        
        Log::info('ProjectOrganization restored', [
            'project_id' => $projectOrganization->project_id,
            'organization_id' => $projectOrganization->organization_id,
        ]);
    }

    /**
     * Handle the ProjectOrganization "force deleted" event.
     */
    public function forceDeleted(ProjectOrganization $projectOrganization): void
    {
        $this->invalidateCache($projectOrganization);
        
        Log::info('ProjectOrganization force deleted', [
            'project_id' => $projectOrganization->project_id,
            'organization_id' => $projectOrganization->organization_id,
        ]);
    }
    
    /**
     * Инвалидировать кэш для проекта и организации
     */
    private function invalidateCache(ProjectOrganization $projectOrganization): void
    {
        $cacheKey = "project:{$projectOrganization->project_id}:org:{$projectOrganization->organization_id}:role";
        
        Cache::forget($cacheKey);
        
        // Также инвалидируем кэш для project context
        $contextCacheKey = "project:{$projectOrganization->project_id}:org:{$projectOrganization->organization_id}:context";
        Cache::forget($contextCacheKey);
    }
}
