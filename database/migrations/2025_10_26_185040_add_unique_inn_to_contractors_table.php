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
        echo "\n🔍 [Migration] Starting contractors INN unique constraint migration\n";
        Log::info('[Migration] Starting contractors INN unique constraint migration');
        
        // Шаг 1: Обработка дубликатов INN в рамках одной организации
        $duplicatesProcessed = $this->handleDuplicateInnInSameOrganization();
        echo "✅ [Migration] Processed {$duplicatesProcessed} duplicate contractors\n";
        Log::info('[Migration] Processed duplicates', ['count' => $duplicatesProcessed]);

        // Шаг 2: Автоматическая синхронизация с существующими организациями
        $this->syncContractorsWithExistingOrganizations();

        // Шаг 3: Проверка что дубликатов больше нет
        $remainingDuplicates = DB::table('contractors')
            ->select('inn', 'organization_id')
            ->whereNull('deleted_at')
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->groupBy('inn', 'organization_id')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($remainingDuplicates > 0) {
            echo "❌ [Migration] ERROR: Still have {$remainingDuplicates} duplicates after processing!\n";
            Log::error('[Migration] Still have duplicates after processing', ['count' => $remainingDuplicates]);
            throw new \Exception("Cannot add unique constraint: {$remainingDuplicates} duplicate INN-organization pairs still exist");
        }

        echo "✅ [Migration] No duplicates remaining, adding unique index...\n";
        
        // Шаг 4: Добавление составного уникального индекса
        Schema::table('contractors', function (Blueprint $table) {
            $table->unique(['inn', 'organization_id'], 'contractors_inn_org_unique');
        });

        echo "🎉 [Migration] Unique composite index on contractors added successfully!\n";
        Log::info('[Migration] Unique composite index on contractors (inn, organization_id) added successfully');
    }

    /**
     * Обработка дубликатов INN в рамках одной организации
     * 
     * Стратегия: Для каждой пары (inn, organization_id) где есть дубликаты,
     * оставляем самого старого подрядчика, а у остальных добавляем суффикс к INN.
     * 
     * @return int Количество обработанных дубликатов
     */
    private function handleDuplicateInnInSameOrganization(): int
    {
        Log::info('[Migration] Starting to check for duplicate INNs in contractors...');
        
        // Сначала посмотрим сколько вообще записей с INN
        $totalWithInn = DB::table('contractors')
            ->whereNull('deleted_at')
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->count();
            
        Log::info('[Migration] Total contractors with INN: ' . $totalWithInn);
        
        // Находим дубликаты INN в рамках одной организации
        $duplicates = DB::table('contractors')
            ->select('inn', 'organization_id', DB::raw('COUNT(*) as dup_count'))
            ->whereNull('deleted_at')
            ->whereNotNull('inn')
            ->where('inn', '!=', '')
            ->groupBy('inn', 'organization_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        Log::info('[Migration] Duplicate query returned ' . $duplicates->count() . ' results');

        if ($duplicates->isEmpty()) {
            Log::info('[Migration] No duplicate INNs found in contractors within same organization');
            return 0;
        }

        Log::warning('[Migration] Found ' . $duplicates->count() . ' duplicate INN-organization pairs in contractors', [
            'duplicates' => $duplicates->map(function($d) {
                return [
                    'inn' => $d->inn,
                    'organization_id' => $d->organization_id,
                    'count' => $d->dup_count
                ];
            })->toArray()
        ]);

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
            $contractorsToUpdate = $contractors->skip(1);
            Log::info('[Migration] Will update ' . $contractorsToUpdate->count() . ' duplicate contractors');
            
            foreach ($contractorsToUpdate as $contractor) {
                $oldInn = $duplicate->inn; // Используем оригинальный ИНН из запроса
                $newInn = $oldInn . '-DUP-' . $contractor->id;
                
                Log::info('[Migration] About to update contractor', [
                    'id' => $contractor->id,
                    'old_inn' => $contractor->inn,
                    'new_inn' => $newInn
                ]);
                
                $updated = DB::table('contractors')
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
                    'updated_rows' => $updated,
                    'organization_id' => $contractor->organization_id,
                    'reason' => 'Duplicate in same org - original kept in contractor #' . $keepContractor->id
                ]);

                if ($updated > 0) {
                    $processedCount++;
                } else {
                    Log::error('[Migration] Failed to update contractor', [
                        'id' => $contractor->id,
                        'updated_rows' => $updated
                    ]);
                }
            }
        }

        Log::info('[Migration] Finished processing duplicate contractors', [
            'processed_count' => $processedCount
        ]);

        return $processedCount;
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
            $projectsSynced = $this->syncOrganizationToProjects($item->contractor_id, $item->match_org_id);
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
     * Находит все проекты где у подрядчика есть контракты
     * и добавляет зарегистрированную организацию в эти проекты как подрядчика.
     * 
     * @param int $contractorId ID подрядчика
     * @param int $registeredOrgId ID зарегистрированной организации
     * @return int Количество синхронизированных проектов
     */
    private function syncOrganizationToProjects(int $contractorId, int $registeredOrgId): int
    {
        // Находим все уникальные проекты где у подрядчика есть контракты
        $projectIds = DB::table('contracts')
            ->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('project_id')
            ->toArray();

        if (empty($projectIds)) {
            return 0;
        }

        $syncedCount = 0;

        foreach ($projectIds as $projectId) {
            // Проверяем не участвует ли уже зарегистрированная организация в этом проекте
            $alreadyExists = DB::table('project_organization')
                ->where('project_id', $projectId)
                ->where('organization_id', $registeredOrgId)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            // Получаем permissions для роли contractor
            $permissions = json_encode([
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
                'organization_id' => $registeredOrgId,
                'role' => 'contractor',
                'role_new' => 'contractor',
                'permissions' => $permissions,
                'is_active' => true,
                'added_by_user_id' => null,
                'invited_at' => now(),
                'accepted_at' => now(),
                'metadata' => json_encode([
                    'auto_synced' => true,
                    'synced_from_contractor_id' => $contractorId,
                    'synced_at' => now()->toDateTimeString(),
                    'reason' => 'Migration: Organization registered with contractor INN - has contracts in this project'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('[Migration] Added organization to project as contractor', [
                'project_id' => $projectId,
                'organization_id' => $registeredOrgId,
                'role' => 'contractor',
                'contractor_id' => $contractorId
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
