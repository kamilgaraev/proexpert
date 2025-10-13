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
     * Автоматически настраиваем S3 бакет и активируем базовый склад для новой организации
     */
    public function created(Organization $organization): void
    {
        // Устанавливаем S3 бакет, если не установлен
        $this->setupS3Bucket($organization);
        
        // Активируем базовый склад по умолчанию
        $this->activateBasicWarehouse($organization);
    }

    /**
     * Настроить S3 бакет для организации
     */
    protected function setupS3Bucket(Organization $organization): void
    {
        // Если s3_bucket уже установлен при создании, ничего не делаем
        if ($organization->s3_bucket) {
            return;
        }

        // Устанавливаем общий бакет для всех организаций
        $mainBucket = config('filesystems.disks.s3.bucket', 'prohelper-storage');
        
        $organization->forceFill([
            's3_bucket' => $mainBucket,
            'bucket_region' => 'ru-central1',
        ])->saveQuietly();

        Log::info("S3 бакет автоматически установлен для организации {$organization->id}: {$mainBucket}");
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

