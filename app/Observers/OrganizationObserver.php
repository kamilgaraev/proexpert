<?php

namespace App\Observers;

use App\Models\Organization;
use App\Models\Module;
use App\Models\OrganizationModuleActivation;
use Illuminate\Support\Facades\Log;

class OrganizationObserver
{
    /**
     * Handle the Organization "created" event.
     * Автоматически активируем базовый склад для новой организации
     */
    public function created(Organization $organization): void
    {
        // Активируем базовый склад по умолчанию
        $this->activateBasicWarehouse($organization);
    }

    /**
     * Активировать базовый склад для организации
     */
    protected function activateBasicWarehouse(Organization $organization): void
    {
        $basicWarehouseModule = Module::where('slug', 'basic-warehouse')->first();
        
        if ($basicWarehouseModule) {
            // Проверяем, не активирован ли уже
            $existing = OrganizationModuleActivation::where('organization_id', $organization->id)
                ->where('module_id', $basicWarehouseModule->id)
                ->first();
            
            if (!$existing) {
                OrganizationModuleActivation::create([
                    'organization_id' => $organization->id,
                    'module_id' => $basicWarehouseModule->id,
                    'activated_at' => now(),
                    'is_active' => true,
                    'module_settings' => [
                        'auto_activated' => true,
                        'is_default' => true,
                    ],
                ]);
                
                Log::info("Basic warehouse автоматически активирован для организации {$organization->id}");
            }
        }
    }
}

