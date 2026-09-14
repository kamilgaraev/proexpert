<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceConstraint(true);

        DB::statement(<<<'SQL'
CREATE INDEX contracts_customer_side_compatible_lookup_idx
ON contracts (organization_id, project_id, status, date DESC)
WHERE deleted_at IS NULL
  AND contract_side_type IN ('customer_to_general_contractor', 'general_contract')
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX contracts_customer_side_compatible_executor_idx
ON contracts (organization_id, contractor_id)
WHERE deleted_at IS NULL
  AND contract_side_type IN ('customer_to_general_contractor', 'general_contract')
SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
UPDATE contracts SET contract_side_type = CASE contract_side_type
    WHEN 'general_contract' THEN 'customer_to_general_contractor'
    WHEN 'contract' THEN 'general_contractor_to_contractor'
    WHEN 'general_contractor_supply' THEN 'general_contractor_to_supplier'
    WHEN 'subcontract' THEN 'contractor_to_subcontractor'
    WHEN 'contractor_supply' THEN 'contractor_to_supplier'
    WHEN 'subcontractor_supply' THEN 'subcontractor_to_supplier'
END
WHERE contract_side_type IN ('general_contract', 'contract', 'general_contractor_supply', 'subcontract', 'contractor_supply', 'subcontractor_supply')
SQL);
        $this->replaceConstraint(false);
        DB::statement('DROP INDEX contracts_customer_side_compatible_lookup_idx');
        DB::statement('DROP INDEX contracts_customer_side_compatible_executor_idx');
    }

    private function replaceConstraint(bool $canonical): void
    {
        $customer = $canonical ? "'customer_to_general_contractor', 'general_contract'" : "'customer_to_general_contractor'";
        $contract = $canonical ? "'general_contractor_to_contractor', 'contract'" : "'general_contractor_to_contractor'";
        $subcontract = $canonical ? "'contractor_to_subcontractor', 'subcontract'" : "'contractor_to_subcontractor'";
        $supply = "'general_contractor_to_supplier', 'contractor_to_supplier', 'subcontractor_to_supplier'";
        if ($canonical) {
            $supply .= ", 'general_contractor_supply', 'contractor_supply', 'subcontractor_supply'";
        }

        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_contract_side_type_consistency_check');
        DB::statement(<<<SQL
ALTER TABLE contracts ADD CONSTRAINT contracts_contract_side_type_consistency_check CHECK (
    contract_side_type IS NULL
    OR (contract_side_type IN ({$customer}) AND supplier_id IS NULL)
    OR (contract_side_type IN ({$supply}) AND supplier_id IS NOT NULL AND contractor_id IS NULL AND coalesce(is_self_execution, false) = false)
    OR (contract_side_type IN ({$contract}) AND supplier_id IS NULL AND (contractor_id IS NOT NULL OR is_self_execution = true))
    OR (contract_side_type IN ({$subcontract}) AND supplier_id IS NULL AND contractor_id IS NOT NULL AND coalesce(is_self_execution, false) = false)
)
SQL);
    }
};
