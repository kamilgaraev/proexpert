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
     * Создает записи подрядчиков типа SELF_EXECUTION для всех существующих организаций.
     */
    public function up(): void
    {
        Log::info('[Migration] Starting self-execution contractors creation...');
        
        // Получаем все активные организации
        $organizations = DB::table('organizations')
            ->whereNull('deleted_at')
            ->get();

        $createdCount = 0;
        $skippedCount = 0;

        foreach ($organizations as $organization) {
            // Проверяем, нет ли уже записи самоподряда для этой организации
            $exists = DB::table('contractors')
                ->where('organization_id', $organization->id)
                ->where('contractor_type', 'self_execution')
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                Log::info('[Migration] Self-execution contractor already exists', [
                    'organization_id' => $organization->id,
                    'organization_name' => $organization->name
                ]);
                $skippedCount++;
                continue;
            }

            // Создаем запись подрядчика самоподряда
            // Примечание: inn оставляем null, чтобы избежать конфликта с unique constraint (organization_id, inn)
            // так как у организации может быть подрядчик с таким же ИНН
            $contractorId = DB::table('contractors')->insertGetId([
                'organization_id' => $organization->id,
                'source_organization_id' => $organization->id,
                'name' => 'Собственные силы',
                'contact_person' => null,
                'phone' => $organization->phone,
                'email' => $organization->email,
                'legal_address' => $organization->address,
                'inn' => null, // Не указываем ИНН для избежания конфликтов с unique constraint
                'kpp' => null,
                'bank_details' => null,
                'notes' => 'Автоматически созданный подрядчик для учета работ собственными силами (хозяйственный способ)',
                'contractor_type' => 'self_execution',
                'contractor_invitation_id' => null,
                'connected_at' => now(),
                'sync_settings' => null,
                'last_sync_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('[Migration] Created self-execution contractor', [
                'contractor_id' => $contractorId,
                'organization_id' => $organization->id,
                'organization_name' => $organization->name
            ]);

            $createdCount++;
        }

        Log::info('[Migration] Self-execution contractors creation completed', [
            'total_organizations' => $organizations->count(),
            'created' => $createdCount,
            'skipped' => $skippedCount
        ]);

        echo "\n✅ [Migration] Created {$createdCount} self-execution contractors\n";
        echo "ℹ️  [Migration] Skipped {$skippedCount} organizations (already have self-execution contractor)\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Log::info('[Migration] Rolling back self-execution contractors creation...');
        
        $deleted = DB::table('contractors')
            ->where('contractor_type', 'self_execution')
            ->delete();

        Log::info('[Migration] Deleted self-execution contractors', [
            'count' => $deleted
        ]);

        echo "\n✅ [Migration] Deleted {$deleted} self-execution contractors\n";
    }
};

