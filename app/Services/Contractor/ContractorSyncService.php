<?php

namespace App\Services\Contractor;

use App\Models\Contractor;
use App\Models\Organization;
use App\Enums\ContractorType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для синхронизации подрядчиков с организациями по ИНН
 * 
 * Обеспечивает автоматическую связь подрядчиков (созданных вручную)
 * с организациями при регистрации последних в системе.
 */
class ContractorSyncService
{
    /**
     * Синхронизировать подрядчиков с организацией по ИНН
     * 
     * Находит всех подрядчиков с ИНН соответствующим tax_number организации
     * и устанавливает связь с организацией. Также синхронизирует участие в проектах.
     * 
     * @param Organization $organization Организация для синхронизации
     * @return array{contractors: int, projects: int} Количество синхронизированных подрядчиков и проектов
     */
    public function syncContractorWithOrganization(Organization $organization): array
    {
        // Проверяем что у организации есть tax_number
        if (empty($organization->tax_number)) {
            Log::info('[ContractorSyncService] Organization has no tax_number, skipping sync', [
                'organization_id' => $organization->id
            ]);
            return ['contractors' => 0, 'projects' => 0];
        }

        Log::info('[ContractorSyncService] Starting synchronization', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'tax_number' => $organization->tax_number
        ]);

        $syncedContractorsCount = 0;
        $syncedProjectsCount = 0;

        DB::transaction(function () use ($organization, &$syncedContractorsCount, &$syncedProjectsCount) {
            // Находим всех подрядчиков с таким ИНН
            $contractors = $this->findContractorsByInn($organization->tax_number);

            if ($contractors->isEmpty()) {
                Log::info('[ContractorSyncService] No contractors found for synchronization');
                return;
            }

            Log::info('[ContractorSyncService] Found contractors to sync', [
                'count' => $contractors->count(),
                'contractor_ids' => $contractors->pluck('id')->toArray()
            ]);

            foreach ($contractors as $contractor) {
                // Синхронизируем подрядчика
                $this->updateContractorWithOrganization($contractor, $organization);
                $syncedContractorsCount++;

                // Синхронизируем проекты для каждого подрядчика
                $projectsCount = $this->syncOrganizationToContractorProjects($contractor, $organization);
                $syncedProjectsCount += $projectsCount;
            }
        });

        Log::info('[ContractorSyncService] Synchronization completed', [
            'organization_id' => $organization->id,
            'synced_contractors' => $syncedContractorsCount,
            'synced_projects' => $syncedProjectsCount
        ]);

        return [
            'contractors' => $syncedContractorsCount,
            'projects' => $syncedProjectsCount
        ];
    }

    /**
     * Найти подрядчиков по ИНН
     * 
     * Возвращает всех подрядчиков у которых:
     * - INN совпадает с переданным значением
     * - Еще не синхронизированы (source_organization_id is null)
     * - Не удалены
     * 
     * @param string $inn ИНН для поиска
     * @return Collection<Contractor>
     */
    public function findContractorsByInn(string $inn): Collection
    {
        if (empty($inn)) {
            return collect();
        }

        return Contractor::where('inn', $inn)
            ->whereNull('source_organization_id') // Еще не синхронизированы
            ->whereNull('deleted_at')
            ->with(['organization', 'contracts']) // Подгружаем связи для логирования
            ->get();
    }

    /**
     * Обновить подрядчика данными организации
     * 
     * @param Contractor $contractor Подрядчик для обновления
     * @param Organization $organization Организация-источник данных
     */
    private function updateContractorWithOrganization(Contractor $contractor, Organization $organization): void
    {
        $updateData = [
            'source_organization_id' => $organization->id,
            'contractor_type' => ContractorType::INVITED_ORGANIZATION,
            'connected_at' => now(),
        ];

        // Обновляем пустые поля из организации (если они пустые у подрядчика)
        if (empty($contractor->name)) {
            $updateData['name'] = $organization->name;
        }
        if (empty($contractor->email)) {
            $updateData['email'] = $organization->email;
        }
        if (empty($contractor->phone)) {
            $updateData['phone'] = $organization->phone;
        }
        if (empty($contractor->legal_address)) {
            $updateData['legal_address'] = $organization->address;
        }

        // Выполняем обновление
        $contractor->update($updateData);

        Log::info('[ContractorSyncService] Contractor synchronized', [
            'contractor_id' => $contractor->id,
            'contractor_name' => $contractor->name,
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'contracts_count' => $contractor->contracts()->count(),
            'fields_updated' => array_keys($updateData)
        ]);
    }

    /**
     * Проверить доступность ИНН для регистрации
     * 
     * Возвращает информацию о том:
     * - Доступен ли ИНН (не занят другой организацией)
     * - Есть ли подрядчики с таким ИНН (которые будут синхронизированы)
     * - Информация о контрактах и проектах для синхронизации
     * 
     * @param string $inn ИНН для проверки
     * @return array{available: bool, message: string, has_contractors: bool, contractor_info: array|null}
     */
    public function checkInnAvailability(string $inn): array
    {
        // Проверяем существование организации с таким ИНН
        $organizationExists = Organization::where('tax_number', $inn)
            ->whereNull('deleted_at')
            ->exists();

        // Находим подрядчиков с таким ИНН
        $contractors = Contractor::where('inn', $inn)
            ->whereNull('deleted_at')
            ->with('organization:id,name')
            ->get();

        $contractorInfo = null;
        if ($contractors->isNotEmpty()) {
            // Подсчитываем количество контрактов и проектов
            $totalContracts = 0;
            $projectIds = [];

            foreach ($contractors as $contractor) {
                $totalContracts += $contractor->contracts()->count();

                // Подсчитываем проекты где у подрядчика есть контракты
                $contractorProjectIds = DB::table('contracts')
                    ->where('contractor_id', $contractor->id)
                    ->whereNull('deleted_at')
                    ->distinct()
                    ->pluck('project_id')
                    ->toArray();
                
                $projectIds = array_merge($projectIds, $contractorProjectIds);
            }

            $projectIds = array_unique($projectIds);

            $contractorInfo = [
                'count' => $contractors->count(),
                'names' => $contractors->pluck('name')->take(3)->toArray(),
                'contracts_count' => $totalContracts,
                'projects_count' => count($projectIds),
                'organizations' => $contractors->pluck('organization.name')->unique()->values()->toArray()
            ];
        }

        $message = $organizationExists
            ? 'Организация с таким ИНН уже зарегистрирована в системе'
            : ($contractors->isNotEmpty()
                ? "ИНН доступен. При регистрации будет автоматически связан с {$contractors->count()} подрядчиком(ами), {$contractorInfo['contracts_count']} контрактом(ами) и {$contractorInfo['projects_count']} проектом(ами)"
                : 'ИНН доступен для регистрации');

        return [
            'available' => !$organizationExists,
            'message' => $message,
            'has_contractors' => $contractors->isNotEmpty(),
            'contractor_info' => $contractorInfo
        ];
    }

    /**
     * Проверить может ли подрядчик с таким ИНН быть создан в организации
     * 
     * @param string $inn ИНН подрядчика
     * @param int $organizationId ID организации
     * @param int|null $exceptContractorId ID подрядчика который нужно исключить из проверки (для update)
     * @return array{can_create: bool, message: string, existing_contractor: array|null}
     */
    public function checkContractorInnAvailability(string $inn, int $organizationId, ?int $exceptContractorId = null): array
    {
        // Ищем существующего подрядчика с таким ИНН в этой организации
        $query = Contractor::where('inn', $inn)
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at');

        if ($exceptContractorId) {
            $query->where('id', '!=', $exceptContractorId);
        }

        $existingContractor = $query->first();

        if ($existingContractor) {
            return [
                'can_create' => false,
                'message' => 'Подрядчик с таким ИНН уже существует в вашей организации',
                'existing_contractor' => [
                    'id' => $existingContractor->id,
                    'name' => $existingContractor->name,
                    'contracts_count' => $existingContractor->contracts()->count()
                ]
            ];
        }

        // Проверяем существует ли организация с таким ИНН (для автоматической связи)
        $linkedOrganization = Organization::where('tax_number', $inn)
            ->whereNull('deleted_at')
            ->first();

        $message = $linkedOrganization
            ? "ИНН доступен. Подрядчик будет автоматически связан с организацией '{$linkedOrganization->name}'"
            : 'ИНН доступен для создания подрядчика';

        return [
            'can_create' => true,
            'message' => $message,
            'existing_contractor' => null,
            'linked_organization' => $linkedOrganization ? [
                'id' => $linkedOrganization->id,
                'name' => $linkedOrganization->name
            ] : null
        ];
    }

    /**
     * Автоматическая установка связи при создании подрядчика
     * 
     * Проверяет существует ли организация с таким ИНН и устанавливает связь.
     * Вызывается при создании подрядчика.
     * 
     * @param Contractor $contractor Созданный подрядчик
     * @return bool True если связь установлена
     */
    public function autoLinkContractorToOrganization(Contractor $contractor): bool
    {
        if (empty($contractor->inn)) {
            return false;
        }

        // Ищем организацию с таким tax_number
        $organization = Organization::where('tax_number', $contractor->inn)
            ->whereNull('deleted_at')
            ->first();

        if (!$organization) {
            return false;
        }

        // Устанавливаем связь
        $contractor->update([
            'source_organization_id' => $organization->id,
            'contractor_type' => ContractorType::INVITED_ORGANIZATION,
            'connected_at' => now(),
        ]);

        Log::info('[ContractorSyncService] Auto-linked contractor with organization', [
            'contractor_id' => $contractor->id,
            'contractor_name' => $contractor->name,
            'organization_id' => $organization->id,
            'organization_name' => $organization->name
        ]);

        return true;
    }

    /**
     * Синхронизировать организацию с проектами подрядчика
     * 
     * Находит все проекты где у подрядчика есть контракты
     * и добавляет зарегистрированную организацию в эти проекты как подрядчика.
     * 
     * @param Contractor $contractor Подрядчик чьи проекты синхронизируются
     * @param Organization $registeredOrganization Зарегистрированная организация
     * @return int Количество синхронизированных проектов
     */
    private function syncOrganizationToContractorProjects(Contractor $contractor, Organization $registeredOrganization): int
    {
        // Находим все уникальные проекты где у подрядчика есть контракты
        $projectIds = DB::table('contracts')
            ->where('contractor_id', $contractor->id)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('project_id')
            ->toArray();

        if (empty($projectIds)) {
            Log::info('[ContractorSyncService] No contracts/projects found for contractor', [
                'contractor_id' => $contractor->id
            ]);
            return 0;
        }

        Log::info('[ContractorSyncService] Found projects with contractor contracts', [
            'contractor_id' => $contractor->id,
            'project_ids' => $projectIds
        ]);

        $syncedCount = 0;

        foreach ($projectIds as $projectId) {
            // Проверяем не участвует ли уже зарегистрированная организация в этом проекте
            $alreadyExists = DB::table('project_organization')
                ->where('project_id', $projectId)
                ->where('organization_id', $registeredOrganization->id)
                ->exists();

            if ($alreadyExists) {
                Log::info('[ContractorSyncService] Organization already participates in project, skipping', [
                    'project_id' => $projectId,
                    'organization_id' => $registeredOrganization->id
                ]);
                continue;
            }

            // Получаем конфигурацию роли contractor
            $roleConfig = config('role-definitions.contractor', []);
            $permissions = json_encode($roleConfig['permissions'] ?? [
                'view_project',
                'manage_own_contracts',
                'manage_works',
                'manage_warehouse',
                'view_own_finances',
                'create_reports'
            ]);

            // Добавляем организацию в проект как подрядчика
            DB::table('project_organization')->insert([
                'project_id' => $projectId,
                'organization_id' => $registeredOrganization->id,
                'role' => 'contractor',
                'role_new' => 'contractor',
                'permissions' => $permissions,
                'is_active' => true,
                'added_by_user_id' => null,
                'invited_at' => now(),
                'accepted_at' => now(),
                'metadata' => json_encode([
                    'auto_synced' => true,
                    'synced_from_contractor_id' => $contractor->id,
                    'synced_at' => now()->toDateTimeString(),
                    'reason' => 'Organization registered with contractor INN - has contracts in this project'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('[ContractorSyncService] Added organization to project as contractor', [
                'project_id' => $projectId,
                'organization_id' => $registeredOrganization->id,
                'organization_name' => $registeredOrganization->name,
                'role' => 'contractor',
                'contractor_id' => $contractor->id
            ]);

            $syncedCount++;
        }

        return $syncedCount;
    }
}

