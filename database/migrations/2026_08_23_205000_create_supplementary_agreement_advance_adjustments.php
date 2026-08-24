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
        Schema::create('supplementary_agreement_advance_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplementary_agreement_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_document_id')->constrained('payment_documents')->restrictOnDelete();
            $table->decimal('previous_amount', 18, 2);
            $table->decimal('adjusted_amount', 18, 2);
            $table->decimal('amount_delta', 18, 2);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['supplementary_agreement_id', 'payment_document_id'],
                'supplementary_agreement_advance_adjustments_unique'
            );
            $table->index(['contract_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION most_prevent_advance_adjustment_mutation()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'supplementary_agreement_advance_adjustments are append-only';
END;
$$ LANGUAGE plpgsql
SQL);
            DB::statement(<<<'SQL'
CREATE TRIGGER most_advance_adjustments_append_only
BEFORE UPDATE OR DELETE ON supplementary_agreement_advance_adjustments
FOR EACH ROW EXECUTE FUNCTION most_prevent_advance_adjustment_mutation()
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'DROP TRIGGER IF EXISTS most_advance_adjustments_append_only '
                .'ON supplementary_agreement_advance_adjustments'
            );
            DB::statement('DROP FUNCTION IF EXISTS most_prevent_advance_adjustment_mutation()');
        }
        Schema::dropIfExists('supplementary_agreement_advance_adjustments');
    }
};
