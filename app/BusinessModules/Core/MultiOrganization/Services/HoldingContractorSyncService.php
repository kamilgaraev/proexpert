<?php

namespace App\BusinessModules\Core\MultiOrganization\Services;

use App\Enums\ContractorType;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Сервис для управления подрядчиками в рамках холдинга
 * 
 * Обеспечивает автоматическую двустороннюю синхронизацию:
 * - Головная организация видит дочерние как подрядчиков
 * - Дочерние организации видят головную как подрядчика
 * - Дочерние организации видят других дочерних (опционально)
 */
class HoldingContractorSyncService
{
    /**
     * Синхронизирует всех подрядчиков для организации в холдинге
     * Вызывается при добавлении организации в холдинг
     */
    public function syncHoldingContractors(int $organizationId): array
    {
        $org = Organization::find($organizationId);
        
        if (!$org) {
            return $this->errorResult('Организация не найдена');
        }

        DB::beginTransaction();
        
        try {
            $result = [
                'organization_id' => $organizationId,
                'organization_name' => $org->name,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            // Если это холдинг - создаем подрядчиков для всех дочерних
            if ($org->is_holding) {
                $childOrgs = Organization::where('parent_organization_id', $organizationId)
                    ->where('is_active', true)
                    ->get();

                foreach ($childOrgs as $childOrg) {
                    $this->createBidirectionalContractors($org->id, $childOrg->id, $result);
                }
            }
            
            // Если это дочерняя организация - создаем связь с головной
            if ($org->parent_organization_id) {
                $this->createBidirectionalContractors($org->parent_organization_id, $org->id, $result);
                
                // Опционально: добавляем связи с другими дочерними организациями
                $this->createSiblingContractors($org->id, $org->parent_organization_id, $result);
            }

            DB::commit();
            
            // Очищаем кеш
            $this->clearRelatedCache($organizationId);
            
            Log::info('Holding contractors synced', $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to sync holding contractors', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Создает двустороннюю связь подрядчиков между двумя организациями
     */
    protected function createBidirectionalContractors(int $parentOrgId, int $childOrgId, array &$result): void
    {
        $parentOrg = Organization::find($parentOrgId);
        $childOrg = Organization::find($childOrgId);

        if (!$parentOrg || !$childOrg) {
            $result['skipped']++;
            return;
        }

        // 1. Головная видит дочернюю как подрядчика
        $this->ensureContractorExists(
            forOrganizationId: $parentOrgId,
            sourceOrganizationId: $childOrgId,
            sourceOrganization: $childOrg,
            result: $result
        );

        // 2. Дочерняя видит головную как подрядчика
        $this->ensureContractorExists(
            forOrganizationId: $childOrgId,
            sourceOrganizationId: $parentOrgId,
            sourceOrganization: $parentOrg,
            result: $result
        );
    }

    /**
     * Создает связи между дочерними организациями (siblings)
     */
    protected function createSiblingContractors(int $currentOrgId, int $parentOrgId, array &$result): void
    {
        $siblings = Organization::where('parent_organization_id', $parentOrgId)
            ->where('id', '!=', $currentOrgId)
            ->where('is_active', true)
            ->get();

        $currentOrg = Organization::find($currentOrgId);

        foreach ($siblings as $sibling) {
            // Текущая дочерняя видит другую дочернюю
            $this->ensureContractorExists(
                forOrganizationId: $currentOrgId,
                sourceOrganizationId: $sibling->id,
                sourceOrganization: $sibling,
                result: $result
            );

            // Другая дочерняя видит текущую
            $this->ensureContractorExists(
                forOrganizationId: $sibling->id,
                sourceOrganizationId: $currentOrgId,
                sourceOrganization: $currentOrg,
                result: $result
            );
        }
    }

    /**
     * Создает или обновляет подрядчика типа holding_member
     */
    protected function ensureContractorExists(
        int $forOrganizationId,
        int $sourceOrganizationId,
        Organization $sourceOrganization,
        array &$result
    ): void {
        $existing = Contractor::where('organization_id', $forOrganizationId)
            ->where('source_organization_id', $sourceOrganizationId)
            ->first();

        if ($existing) {
            // Если уже существует - обновляем данные и тип
            $updated = $this->updateContractorFromOrganization($existing, $sourceOrganization);
            
            if ($updated) {
                $result['updated']++;
            } else {
                $result['skipped']++;
            }
        } else {
            // Создаем нового подрядчика
            $this->createContractorFromOrganization(
                $forOrganizationId,
                $sourceOrganizationId,
                $sourceOrganization
            );
            
            $result['created']++;
        }
    }

    /**
     * Создает подрядчика из организации
     */
    protected function createContractorFromOrganization(
        int $forOrganizationId,
        int $sourceOrganizationId,
        Organization $sourceOrganization
    ): Contractor {
        return Contractor::create([
            'organization_id' => $forOrganizationId,
            'source_organization_id' => $sourceOrganizationId,
            'contractor_type' => ContractorType::HOLDING_MEMBER,
            'name' => $sourceOrganization->name,
            'inn' => $sourceOrganization->tax_number,
            'legal_address' => $sourceOrganization->address,
            'phone' => $sourceOrganization->phone,
            'email' => $sourceOrganization->email,
            'contact_person' => $sourceOrganization->owners()->first()?->name,
            'connected_at' => now(),
            'last_sync_at' => now(),
            'sync_settings' => [
                'sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn', 'contact_person'],
                'sync_interval_hours' => 6, // Более частая синхронизация для участников холдинга
                'auto_sync_enabled' => true,
            ],
        ]);
    }

    /**
     * Обновляет данные подрядчика из организации
     */
    protected function updateContractorFromOrganization(
        Contractor $contractor,
        Organization $sourceOrganization
    ): bool {
        $changes = [];

        // Обновляем тип, если он отличается
        if ($contractor->contractor_type !== ContractorType::HOLDING_MEMBER) {
            $changes['contractor_type'] = ContractorType::HOLDING_MEMBER;
        }

        // Синхронизируем данные из организации
        $fieldsToSync = [
            'name' => $sourceOrganization->name,
            'inn' => $sourceOrganization->tax_number,
            'legal_address' => $sourceOrganization->address,
            'phone' => $sourceOrganization->phone,
            'email' => $sourceOrganization->email,
        ];

        foreach ($fieldsToSync as $field => $value) {
            if ($contractor->$field !== $value) {
                $changes[$field] = $value;
            }
        }

        if (!empty($changes)) {
            $changes['last_sync_at'] = now();
            $contractor->update($changes);
            
            Log::info('Contractor updated from holding organization', [
                'contractor_id' => $contractor->id,
                'source_org_id' => $sourceOrganization->id,
                'changes' => array_keys($changes),
            ]);
            
            return true;
        }

        return false;
    }

    /**
     * Удаляет подрядчиков при удалении организации из холдинга
     */
    public function removeFromHolding(int $organizationId): array
    {
        DB::beginTransaction();
        
        try {
            $org = Organization::find($organizationId);
            
            if (!$org) {
                return $this->errorResult('Организация не найдена');
            }

            $deleted = 0;

            // Удаляем записи, где эта организация - источник
            $deleted += Contractor::where('source_organization_id', $organizationId)
                ->where('contractor_type', ContractorType::HOLDING_MEMBER->value)
                ->delete();

            // Удаляем записи, где эта организация - владелец
            $deleted += Contractor::where('organization_id', $organizationId)
                ->where('contractor_type', ContractorType::HOLDING_MEMBER->value)
                ->delete();

            DB::commit();
            
            $this->clearRelatedCache($organizationId);
            
            Log::info('Contractors removed from holding', [
                'organization_id' => $organizationId,
                'deleted_count' => $deleted,
            ]);

            return [
                'success' => true,
                'organization_id' => $organizationId,
                'deleted' => $deleted,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to remove contractors from holding', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            
            return $this->errorResult($e->getMessage());
        }
    }

    /**
     * Синхронизирует данные всех holding_member подрядчиков для организации
     */
    public function syncContractorData(int $organizationId): array
    {
        $contractors = Contractor::where('source_organization_id', $organizationId)
            ->where('contractor_type', ContractorType::HOLDING_MEMBER->value)
            ->get();

        $sourceOrg = Organization::find($organizationId);
        
        if (!$sourceOrg) {
            return $this->errorResult('Организация-источник не найдена');
        }

        $synced = 0;
        $errors = [];

        foreach ($contractors as $contractor) {
            try {
                if ($this->updateContractorFromOrganization($contractor, $sourceOrg)) {
                    $synced++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'contractor_id' => $contractor->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->clearRelatedCache($organizationId);

        return [
            'success' => true,
            'organization_id' => $organizationId,
            'total' => $contractors->count(),
            'synced' => $synced,
            'errors' => $errors,
        ];
    }

    /**
     * Очищает связанный кеш
     */
    protected function clearRelatedCache(int $organizationId): void
    {
        Cache::forget("org_scope_full:{$organizationId}");
        Cache::forget("contractors_available:{$organizationId}");
        
        // Очищаем кеш для родительской организации
        $org = Organization::find($organizationId);
        if ($org && $org->parent_organization_id) {
            Cache::forget("org_scope_full:{$org->parent_organization_id}");
            Cache::forget("contractors_available:{$org->parent_organization_id}");
        }
    }

    /**
     * Возвращает результат с ошибкой
     */
    protected function errorResult(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
            'created' => 0,
            'updated' => 0,
            'deleted' => 0,
        ];
    }
}

