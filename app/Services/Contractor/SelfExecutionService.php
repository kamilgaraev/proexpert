<?php

namespace App\Services\Contractor;

use App\Models\Contractor;
use App\Models\Organization;
use App\Enums\ContractorType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SelfExecutionService
{
    /**
     * Получить или создать подрядчика самоподряда для организации
     *
     * @param int $organizationId ID организации
     * @return Contractor
     * @throws \Exception
     */
    public function getOrCreateForOrganization(int $organizationId): Contractor
    {
        Log::info('[SelfExecutionService] Getting or creating self-execution contractor', [
            'organization_id' => $organizationId
        ]);

        // Используем метод модели Contractor
        $contractor = Contractor::getOrCreateSelfExecution($organizationId);

        Log::info('[SelfExecutionService] Self-execution contractor obtained', [
            'contractor_id' => $contractor->id,
            'organization_id' => $organizationId,
            'was_created' => $contractor->wasRecentlyCreated
        ]);

        return $contractor;
    }

    /**
     * Проверить, может ли организация использовать самоподряд
     *
     * @param int $organizationId ID организации
     * @return bool
     */
    public function canUseSelfExecution(int $organizationId): bool
    {
        $organization = Organization::find($organizationId);
        
        if (!$organization) {
            Log::warning('[SelfExecutionService] Organization not found', [
                'organization_id' => $organizationId
            ]);
            return false;
        }

        // Проверяем, что организация активна
        if (!$organization->is_active) {
            Log::info('[SelfExecutionService] Organization is not active', [
                'organization_id' => $organizationId
            ]);
            return false;
        }

        return true;
    }

    /**
     * Проверить, является ли подрядчик записью самоподряда
     *
     * @param int $contractorId ID подрядчика
     * @return bool
     */
    public function isSelfExecutionContractor(int $contractorId): bool
    {
        $contractor = Contractor::find($contractorId);
        
        if (!$contractor) {
            return false;
        }

        return $contractor->isSelfExecution();
    }

    /**
     * Синхронизировать подрядчиков самоподряда для всех организаций
     * Создает записи для организаций, у которых их еще нет
     *
     * @return array Статистика синхронизации
     */
    public function syncSelfExecutionContractors(): array
    {
        Log::info('[SelfExecutionService] Starting synchronization of self-execution contractors');

        $organizations = Organization::whereNull('deleted_at')->get();
        
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($organizations as $organization) {
            try {
                // Проверяем, есть ли уже запись самоподряда
                $exists = Contractor::where('organization_id', $organization->id)
                    ->where('contractor_type', ContractorType::SELF_EXECUTION->value)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Создаем новую запись
                Contractor::getOrCreateSelfExecution($organization->id);
                $created++;

                Log::info('[SelfExecutionService] Created self-execution contractor', [
                    'organization_id' => $organization->id,
                    'organization_name' => $organization->name
                ]);

            } catch (\Exception $e) {
                $errors[] = [
                    'organization_id' => $organization->id,
                    'organization_name' => $organization->name,
                    'error' => $e->getMessage()
                ];

                Log::error('[SelfExecutionService] Failed to create self-execution contractor', [
                    'organization_id' => $organization->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $result = [
            'total_organizations' => $organizations->count(),
            'created' => $created,
            'skipped' => $skipped,
            'errors_count' => count($errors),
            'errors' => $errors
        ];

        Log::info('[SelfExecutionService] Synchronization completed', $result);

        return $result;
    }

    /**
     * Получить подрядчика самоподряда для организации (без создания)
     *
     * @param int $organizationId ID организации
     * @return Contractor|null
     */
    public function getForOrganization(int $organizationId): ?Contractor
    {
        return Contractor::where('organization_id', $organizationId)
            ->where('contractor_type', ContractorType::SELF_EXECUTION->value)
            ->first();
    }

    /**
     * Проверить, принадлежит ли подрядчик самоподряда указанной организации
     *
     * @param int $contractorId ID подрядчика
     * @param int $organizationId ID организации
     * @return bool
     */
    public function belongsToOrganization(int $contractorId, int $organizationId): bool
    {
        $contractor = Contractor::find($contractorId);
        
        if (!$contractor || !$contractor->isSelfExecution()) {
            return false;
        }

        return $contractor->organization_id === $organizationId;
    }
}

