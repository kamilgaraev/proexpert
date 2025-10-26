<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Очистка дубликатов ИНН НАПРЯМУЮ через SQL, включая soft-deleted записи.
     */
    public function up(): void
    {
        echo "\n🧹 [Cleanup] Starting aggressive duplicate cleanup...\n";
        
        // 1. Очистка дубликатов tax_number в organizations (ВКЛЮЧАЯ deleted_at)
        $this->cleanupOrganizationDuplicates();
        
        // 2. Очистка дубликатов inn в contractors (ВКЛЮЧАЯ deleted_at)
        $this->cleanupContractorDuplicates();
        
        echo "✅ [Cleanup] Aggressive cleanup completed!\n";
    }

    private function cleanupOrganizationDuplicates(): void
    {
        echo "🔍 [Cleanup] Checking organizations...\n";
        
        // Находим ВСЕ дубликаты, включая soft-deleted
        $duplicatesQuery = "
            SELECT tax_number, COUNT(*) as cnt
            FROM organizations
            WHERE tax_number IS NOT NULL AND tax_number != ''
            GROUP BY tax_number
            HAVING COUNT(*) > 1
        ";
        
        $duplicates = DB::select($duplicatesQuery);
        
        echo "📊 [Cleanup] Found " . count($duplicates) . " duplicate tax_numbers (including soft-deleted)\n";
        
        if (empty($duplicates)) {
            echo "✅ [Cleanup] No duplicates in organizations\n";
            return;
        }
        
        foreach ($duplicates as $dup) {
            echo "⚠️  [Cleanup] Processing tax_number: {$dup->tax_number} (count: {$dup->cnt})\n";
            
            // Получаем все записи с этим tax_number, отсортированные по дате
            $orgs = DB::select("
                SELECT id, name, tax_number, created_at, deleted_at
                FROM organizations
                WHERE tax_number = ?
                ORDER BY 
                    CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END,
                    created_at ASC,
                    id ASC
            ", [$dup->tax_number]);
            
            // Первую активную (или самую старую) оставляем
            $keepOrg = $orgs[0];
            echo "✓ [Cleanup] Keeping org #{$keepOrg->id}: {$keepOrg->name}\n";
            
            // Остальные обновляем
            for ($i = 1; $i < count($orgs); $i++) {
                $org = $orgs[$i];
                $newTaxNumber = $org->tax_number . '-DUP-' . $org->id;
                
                DB::update("
                    UPDATE organizations
                    SET tax_number = ?, updated_at = NOW()
                    WHERE id = ?
                ", [$newTaxNumber, $org->id]);
                
                echo "  → Updated org #{$org->id}: {$org->tax_number} → {$newTaxNumber}\n";
                
                Log::warning('[Cleanup] Modified duplicate organization', [
                    'id' => $org->id,
                    'name' => $org->name,
                    'old_tax_number' => $org->tax_number,
                    'new_tax_number' => $newTaxNumber,
                    'was_deleted' => $org->deleted_at !== null
                ]);
            }
        }
        
        echo "✅ [Cleanup] Organizations cleanup completed\n";
    }

    private function cleanupContractorDuplicates(): void
    {
        echo "🔍 [Cleanup] Checking contractors...\n";
        
        // Находим ВСЕ дубликаты по (inn, organization_id), включая soft-deleted
        $duplicatesQuery = "
            SELECT inn, organization_id, COUNT(*) as cnt
            FROM contractors
            WHERE inn IS NOT NULL AND inn != ''
            GROUP BY inn, organization_id
            HAVING COUNT(*) > 1
        ";
        
        $duplicates = DB::select($duplicatesQuery);
        
        echo "📊 [Cleanup] Found " . count($duplicates) . " duplicate INN-org pairs (including soft-deleted)\n";
        
        if (empty($duplicates)) {
            echo "✅ [Cleanup] No duplicates in contractors\n";
            return;
        }
        
        foreach ($duplicates as $dup) {
            echo "⚠️  [Cleanup] Processing INN: {$dup->inn} in org #{$dup->organization_id} (count: {$dup->cnt})\n";
            
            // Получаем все записи с этим (inn, organization_id)
            $contractors = DB::select("
                SELECT id, name, inn, organization_id, created_at, deleted_at
                FROM contractors
                WHERE inn = ? AND organization_id = ?
                ORDER BY 
                    CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END,
                    created_at ASC,
                    id ASC
            ", [$dup->inn, $dup->organization_id]);
            
            // Первого активного (или самого старого) оставляем
            $keepContractor = $contractors[0];
            echo "✓ [Cleanup] Keeping contractor #{$keepContractor->id}: {$keepContractor->name}\n";
            
            // Остальные обновляем
            for ($i = 1; $i < count($contractors); $i++) {
                $contractor = $contractors[$i];
                $newInn = $contractor->inn . '-DUP-' . $contractor->id;
                
                DB::update("
                    UPDATE contractors
                    SET inn = ?, updated_at = NOW()
                    WHERE id = ?
                ", [$newInn, $contractor->id]);
                
                echo "  → Updated contractor #{$contractor->id}: {$contractor->inn} → {$newInn}\n";
                
                Log::warning('[Cleanup] Modified duplicate contractor', [
                    'id' => $contractor->id,
                    'name' => $contractor->name,
                    'old_inn' => $contractor->inn,
                    'new_inn' => $newInn,
                    'organization_id' => $contractor->organization_id,
                    'was_deleted' => $contractor->deleted_at !== null
                ]);
            }
        }
        
        echo "✅ [Cleanup] Contractors cleanup completed\n";
    }

    public function down(): void
    {
        // Нет смысла откатывать очистку дубликатов
        echo "⚠️  [Cleanup] Rollback not supported for duplicate cleanup\n";
    }
};

