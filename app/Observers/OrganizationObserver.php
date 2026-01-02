<?php

namespace App\Observers;

use App\BusinessModules\Core\MultiOrganization\Services\HoldingContractorSyncService;
use App\Models\Organization;
use App\Models\MeasurementUnit;
use App\Modules\Core\AccessController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Observer для автоматической синхронизации подрядчиков при изменениях в организациях холдинга
 */
class OrganizationObserver
{
    protected HoldingContractorSyncService $contractorSync;
    protected AccessController $accessController;

    public function __construct(
        HoldingContractorSyncService $contractorSync,
        AccessController $accessController
    ) {
        $this->contractorSync = $contractorSync;
        $this->accessController = $accessController;
    }

    /**
     * Вызывается после создания организации
     */
    public function created(Organization $organization): void
    {
        // Создаем стандартные единицы измерения для новой организации
        $this->createDefaultMeasurementUnits($organization);

        // При создании организации синхронизация не нужна
        // Она будет выполнена при установке parent_organization_id
    }

    /**
     * Создание стандартных единиц измерения для организации
     */
    protected function createDefaultMeasurementUnits(Organization $organization): void
    {
        try {
            $units = MeasurementUnit::getDefaultUnits();
            $now = Carbon::now();
            $insertData = [];

            foreach ($units as $unit) {
                $insertData[] = [
                    'organization_id' => $organization->id,
                    'name' => $unit['name'],
                    'short_name' => $unit['short_name'],
                    'type' => $unit['type'],
                    'is_system' => true,
                    'is_default' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Используем insert для массовой вставки (быстрее)
            if (!empty($insertData)) {
                DB::table('measurement_units')->insert($insertData);
                
                Log::info('Created default measurement units for new organization', [
                    'organization_id' => $organization->id,
                    'count' => count($insertData)
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create default measurement units', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Вызывается после обновления организации
     */
    public function updated(Organization $organization): void
    {
        // Проверяем, изменилось ли поле parent_organization_id
        if ($organization->isDirty('parent_organization_id')) {
            $this->handleParentOrganizationChange($organization);
        }

        // Проверяем, изменились ли данные организации (для синхронизации подрядчиков)
        $syncableFields = ['name', 'phone', 'email', 'address', 'tax_number'];
        $hasChanges = false;
        
        foreach ($syncableFields as $field) {
            if ($organization->isDirty($field)) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            $this->syncContractorData($organization);
        }

        // Проверяем изменение статуса is_active
        if ($organization->isDirty('is_active')) {
            $this->handleActiveStatusChange($organization);
        }
    }

    /**
     * Обрабатывает изменение parent_organization_id
     */
    protected function handleParentOrganizationChange(Organization $organization): void
    {
        $oldParentId = $organization->getOriginal('parent_organization_id');
        $newParentId = $organization->parent_organization_id;

        Log::info('Organization parent changed', [
            'organization_id' => $organization->id,
            'old_parent_id' => $oldParentId,
            'new_parent_id' => $newParentId,
        ]);

        // Удаляем старые связи, если были
        if ($oldParentId) {
            $this->contractorSync->removeFromHolding($organization->id);
        }

        // Создаем новые связи, если добавлена в холдинг
        if ($newParentId && $this->isMultiOrgActive($newParentId)) {
            $result = $this->contractorSync->syncHoldingContractors($organization->id);
            
            Log::info('Organization added to holding, contractors synced', [
                'organization_id' => $organization->id,
                'parent_id' => $newParentId,
                'result' => $result,
            ]);
        }
    }

    /**
     * Синхронизирует данные подрядчиков при изменении данных организации
     */
    protected function syncContractorData(Organization $organization): void
    {
        // Синхронизируем только если организация в холдинге
        if (!$organization->parent_organization_id && !$organization->is_holding) {
            return;
        }

        // Проверяем наличие модуля multi-organization
        if (!$this->isMultiOrgActive($organization->id)) {
            return;
        }

        $result = $this->contractorSync->syncContractorData($organization->id);
        
        if ($result['synced'] > 0) {
            Log::info('Organization data synced to contractors', [
                'organization_id' => $organization->id,
                'synced_count' => $result['synced'],
            ]);
        }
    }

    /**
     * Обрабатывает изменение статуса is_active
     */
    protected function handleActiveStatusChange(Organization $organization): void
    {
        if (!$organization->is_active) {
            // При деактивации организации логируем событие
            // Подрядчики остаются, но при необходимости можно добавить логику их деактивации
            Log::warning('Organization deactivated', [
                'organization_id' => $organization->id,
                'name' => $organization->name,
            ]);
        } else {
            // При активации организации синхронизируем подрядчиков
            if ($organization->parent_organization_id && $this->isMultiOrgActive($organization->parent_organization_id)) {
                $this->contractorSync->syncHoldingContractors($organization->id);
            }
        }
    }

    /**
     * Вызывается перед удалением организации
     */
    public function deleting(Organization $organization): void
    {
        // При удалении организации удаляем связанных подрядчиков
        if ($organization->parent_organization_id || $organization->is_holding) {
            $result = $this->contractorSync->removeFromHolding($organization->id);
            
            Log::info('Organization contractors removed before deletion', [
                'organization_id' => $organization->id,
                'deleted_count' => $result['deleted'] ?? 0,
            ]);
        }
    }

    /**
     * Проверяет, активен ли модуль multi-organization для организации
     */
    protected function isMultiOrgActive(int $organizationId): bool
    {
        try {
            return $this->accessController->hasModuleAccess($organizationId, 'multi-organization');
        } catch (\Exception $e) {
            Log::error('Failed to check multi-organization access', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
