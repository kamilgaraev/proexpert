<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет составной уникальный индекс на (inn, organization_id) в таблице contractors.
     * АВТОМАТИЧЕСКИ обрабатывает существующие дубликаты перед добавлением индекса.
     * Также выполняет автоматическую синхронизацию подрядчиков с существующими организациями по ИНН.
     */
    public function up(): void
    {
        // Шаг 1: Обработка дубликатов INN в рамках одной организации
        $this->handleDuplicateInnInSameOrganization();

        // Шаг 2: Автоматическая синхронизация с существующими организациями
        $this->syncContractorsWithExistingOrganizations();

        // Шаг 3: Добавление составного уникального индекса
        Schema::table('contractors', function (Blueprint $table) {
            // Составной уникальный индекс: INN уникален в рамках organization_id
            // Это означает что один и тот же ИНН может быть у разных организаций,
            // но не может дублироваться внутри одной организации
            $table->unique(['inn', 'organization_id'], 'contractors_inn_org_unique');
        });

        Log::info('[Migration] Unique composite index on contractors (inn, organization_id) added successfully');
    }

    /**
     * Обработка дубликатов INN в рамках одной организации
     * 
     * Стратегия: Для каждой пары (inn, organization_id) где есть дубликаты,
     * оставляем самого старого подрядчика, а у остальных добавляем суффикс к INN.
     */
    private function handleDuplicateInnInSameOrganization(): void
    {
        // Находим дубликаты INN в рамках одной организации
        $duplicates = DB::table('contractors')
            ->select('inn', 'organization_id', DB::raw('COUNT(*) as count'))
            ->whereNull('deleted_at')
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->groupBy('inn', 'organization_id')
            ->having('count', '>', 1)
            ->get();

        if ($duplicates->isEmpty()) {
            Log::info('[Migration] No duplicate INNs found in contractors within same organization');
            return;
        }

        Log::warning('[Migration] Found ' . $duplicates->count() . ' duplicate INN-organization pairs in contractors');

        $processedCount = 0;

        foreach ($duplicates as $duplicate) {
            // Получаем всех подрядчиков с этим INN в этой организации
            $contractors = DB::table('contractors')
                ->where('inn', $duplicate->inn)
                ->where('organization_id', $duplicate->organization_id)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            // Первого (самого старого) оставляем
            $keepContractor = $contractors->first();
            Log::info('[Migration] Keeping contractor', [
                'id' => $keepContractor->id,
                'name' => $keepContractor->name,
                'inn' => $keepContractor->inn,
                'organization_id' => $keepContractor->organization_id
            ]);

            // Остальные помечаем суффиксом
            $duplicateContractors = $contractors->skip(1);
            foreach ($duplicateContractors as $contractor) {
                $newInn = $contractor->inn . '-DUP-' . $contractor->id;
                
                DB::table('contractors')
                    ->where('id', $contractor->id)
                    ->update([
                        'inn' => $newInn,
                        'updated_at' => now()
                    ]);

                Log::warning('[Migration] Modified duplicate contractor INN', [
                    'id' => $contractor->id,
                    'name' => $contractor->name,
                    'old_inn' => $contractor->inn,
                    'new_inn' => $newInn,
                    'organization_id' => $contractor->organization_id,
                    'reason' => 'Duplicate in same org - original kept in contractor #' . $keepContractor->id
                ]);

                $processedCount++;
            }
        }

        Log::info('[Migration] Processed ' . $processedCount . ' duplicate contractors');
    }

    /**
     * Автоматическая синхронизация подрядчиков с существующими организациями по ИНН
     * 
     * Находит подрядчиков, у которых INN совпадает с tax_number зарегистрированных организаций,
     * и автоматически устанавливает связь. Также синхронизирует участие в проектах.
     */
    private function syncContractorsWithExistingOrganizations(): void
    {
        Log::info('[Migration] Starting automatic contractor-organization synchronization...');

        // Находим подрядчиков которые можно синхронизировать
        $contractorsToSync = DB::table('contractors as c')
            ->join('organizations as o', 'c.inn', '=', 'o.tax_number')
            ->whereNull('c.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereNull('c.source_organization_id') // Еще не синхронизированы
            ->whereNotNull('c.inn')
            ->where('c.inn', '!=', '')
            ->where('c.inn', 'NOT LIKE', '%-DUP-%') // Исключаем помеченные как дубликаты
            ->select(
                'c.id as contractor_id',
                'c.name as contractor_name',
                'c.inn',
                'c.organization_id as contractor_org_id',
                'o.id as match_org_id',
                'o.name as match_org_name'
            )
            ->get();

        if ($contractorsToSync->isEmpty()) {
            Log::info('[Migration] No contractors need synchronization');
            return;
        }

        Log::info('[Migration] Found ' . $contractorsToSync->count() . ' contractors to synchronize');

        $syncedContractorsCount = 0;
        $syncedProjectsCount = 0;

        foreach ($contractorsToSync as $item) {
            // Получаем данные организации для обновления пустых полей
            $organization = DB::table('organizations')->where('id', $item->match_org_id)->first();
            
            // Подготавливаем данные для обновления
            $updateData = [
                'source_organization_id' => $item->match_org_id,
                'contractor_type' => 'invited_organization',
                'connected_at' => now(),
                'updated_at' => now()
            ];

            // Обновляем пустые поля из организации (если они пустые у подрядчика)
            $contractor = DB::table('contractors')->where('id', $item->contractor_id)->first();
            
            if (empty($contractor->name) && !empty($organization->name)) {
                $updateData['name'] = $organization->name;
            }
            if (empty($contractor->email) && !empty($organization->email)) {
                $updateData['email'] = $organization->email;
            }
            if (empty($contractor->phone) && !empty($organization->phone)) {
                $updateData['phone'] = $organization->phone;
            }
            if (empty($contractor->legal_address) && !empty($organization->address)) {
                $updateData['legal_address'] = $organization->address;
            }

            // Выполняем обновление подрядчика
            DB::table('contractors')
                ->where('id', $item->contractor_id)
                ->update($updateData);

            Log::info('[Migration] Synchronized contractor with organization', [
                'contractor_id' => $item->contractor_id,
                'contractor_name' => $item->contractor_name,
                'inn' => $item->inn,
                'matched_organization_id' => $item->match_org_id,
                'matched_organization_name' => $item->match_org_name,
                'fields_updated' => array_keys($updateData)
            ]);

            $syncedContractorsCount++;

            // Синхронизируем проекты (Project-Based RBAC)
            $projectsSynced = $this->syncOrganizationToProjects($item->contractor_org_id, $item->match_org_id, $item->contractor_id);
            $syncedProjectsCount += $projectsSynced;
        }

        Log::info('[Migration] Successfully synchronized contractors and projects', [
            'contractors_count' => $syncedContractorsCount,
            'projects_count' => $syncedProjectsCount
        ]);
    }

    /**
     * Синхронизировать организацию с проектами подрядчика
     * 
     * @param int $contractorOwnerOrgId ID организации-владельца подрядчика
     * @param int $registeredOrgId ID зарегистрированной организации
     * @param int $contractorId ID подрядчика для логирования
     * @return int Количество синхронизированных проектов
     */
    private function syncOrganizationToProjects(int $contractorOwnerOrgId, int $registeredOrgId, int $contractorId): int
    {
        // Находим все проекты где участвует организация-владелец подрядчика
        $projectParticipations = DB::table('project_organization')
            ->where('organization_id', $contractorOwnerOrgId)
            ->whereIn('role_new', ['contractor', 'child_contractor'])
            ->where('is_active', true)
            ->get();

        if ($projectParticipations->isEmpty()) {
            return 0;
        }

        $syncedCount = 0;

        foreach ($projectParticipations as $participation) {
            // Проверяем не участвует ли уже зарегистрированная организация в этом проекте
            $alreadyExists = DB::table('project_organization')
                ->where('project_id', $participation->project_id)
                ->where('organization_id', $registeredOrgId)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            // Добавляем организацию в проект с той же ролью
            DB::table('project_organization')->insert([
                'project_id' => $participation->project_id,
                'organization_id' => $registeredOrgId,
                'role' => $participation->role ?? 'contractor',
                'role_new' => $participation->role_new ?? 'contractor',
                'permissions' => $participation->permissions,
                'is_active' => true,
                'added_by_user_id' => null,
                'invited_at' => now(),
                'accepted_at' => now(),
                'metadata' => json_encode([
                    'auto_synced' => true,
                    'synced_from_contractor_id' => $contractorId,
                    'synced_from_organization_id' => $contractorOwnerOrgId,
                    'synced_at' => now()->toDateTimeString(),
                    'reason' => 'Migration: Organization registered with contractor INN'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('[Migration] Added organization to project', [
                'project_id' => $participation->project_id,
                'organization_id' => $registeredOrgId,
                'role' => $participation->role_new ?? $participation->role,
                'contractor_id' => $contractorId,
                'source_organization_id' => $contractorOwnerOrgId
            ]);

            $syncedCount++;
        }

        return $syncedCount;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropUnique('contractors_inn_org_unique');
        });

        // Примечание: Не откатываем синхронизацию, так как это может нарушить целостность данных
        // Если необходимо откатить синхронизацию, это нужно делать вручную

        Log::info('[Migration] Unique composite index on contractors removed');
    }
};
