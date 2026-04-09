<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE contracts
ADD CONSTRAINT contracts_contract_side_type_consistency_check
CHECK (
    contract_side_type IS NULL
    OR (
        contract_side_type = 'customer_to_general_contractor'
        AND supplier_id IS NULL
    )
    OR (
        contract_side_type = 'general_contractor_to_supplier'
        AND supplier_id IS NOT NULL
        AND contractor_id IS NULL
    )
    OR (
        contract_side_type = 'general_contractor_to_contractor'
        AND supplier_id IS NULL
        AND (
            contractor_id IS NOT NULL
            OR is_self_execution = true
        )
    )
    OR (
        contract_side_type = 'contractor_to_subcontractor'
        AND supplier_id IS NULL
        AND contractor_id IS NOT NULL
    )
)
SQL);

        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS contracts_customer_side_lookup_idx
ON contracts (organization_id, project_id, status, date DESC)
WHERE deleted_at IS NULL
  AND contract_side_type = 'customer_to_general_contractor'
SQL);

        DB::statement(<<<'SQL'
CREATE INDEX IF NOT EXISTS contracts_customer_side_executor_idx
ON contracts (organization_id, contractor_id)
WHERE deleted_at IS NULL
  AND contract_side_type = 'customer_to_general_contractor'
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contracts_customer_side_executor_idx');
        DB::statement('DROP INDEX IF EXISTS contracts_customer_side_lookup_idx');
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT IF EXISTS contracts_contract_side_type_consistency_check');
    }
};
