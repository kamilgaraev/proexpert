<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->jsonb('direct_party')->nullable();
            $table->string('direct_direction', 16)->nullable();
        });
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_direct_party_shape CHECK (
            (direct_party IS NULL AND direct_direction IS NULL) OR
            (direct_party IS NOT NULL AND direct_direction IS NOT NULL AND jsonb_typeof(direct_party) = 'object' AND direct_direction IN ('income', 'expense')
                AND contract_side_type = 'contract' AND contractor_id IS NULL AND supplier_id IS NULL AND superior_organization_id IS NULL AND coalesce(is_self_execution, false) = false)
        )");
        $this->replaceSideConstraint(true);
    }

    public function down(): void
    {
        if (DB::table('contracts')->whereNotNull('direct_party')->exists()) throw new RuntimeException('direct_contracts_require_preservation');
        $this->replaceSideConstraint(false);
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_direct_party_shape');
        Schema::table('contracts', function (Blueprint $table): void { $table->dropColumn(['direct_party', 'direct_direction']); });
    }

    private function replaceSideConstraint(bool $direct): void
    {
        $directAlternative = $direct ? " OR (contract_side_type = 'contract' AND direct_party IS NOT NULL AND direct_direction IS NOT NULL AND supplier_id IS NULL AND contractor_id IS NULL AND superior_organization_id IS NULL AND coalesce(is_self_execution, false) = false)" : '';
        DB::statement('ALTER TABLE contracts DROP CONSTRAINT contracts_contract_side_type_consistency_check');
        DB::statement("ALTER TABLE contracts ADD CONSTRAINT contracts_contract_side_type_consistency_check CHECK (
            contract_side_type IS NULL
            OR (contract_side_type IN ('customer_to_general_contractor', 'general_contract') AND supplier_id IS NULL)
            OR (contract_side_type IN ('general_contractor_to_supplier', 'contractor_to_supplier', 'subcontractor_to_supplier', 'general_contractor_supply', 'contractor_supply', 'subcontractor_supply') AND supplier_id IS NOT NULL AND contractor_id IS NULL AND coalesce(is_self_execution, false) = false)
            OR (contract_side_type IN ('general_contractor_to_contractor', 'contract') AND supplier_id IS NULL AND (contractor_id IS NOT NULL OR is_self_execution = true))
            OR (contract_side_type IN ('contractor_to_subcontractor', 'subcontract') AND supplier_id IS NULL AND contractor_id IS NOT NULL AND coalesce(is_self_execution, false) = false)
            {$directAlternative}
        )");
    }
};
