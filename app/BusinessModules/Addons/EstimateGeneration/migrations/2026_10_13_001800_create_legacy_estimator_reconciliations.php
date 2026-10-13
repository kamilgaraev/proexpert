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
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Legacy estimator reconciliation requires PostgreSQL.');
        }
        Schema::create('estimate_generation_legacy_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('allocation_id')->constrained('organization_resource_allocations')->restrictOnDelete()->unique();
            $table->foreignId('commercial_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('system_admin_id')->constrained('system_admins')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('command_hash', 64);
            $table->char('allocation_snapshot_hash', 64);
            $table->char('purchase_snapshot_hash', 64);
            $table->char('proof_hash', 64);
            $table->jsonb('proof');
            $table->jsonb('sale_price_snapshot');
            $table->bigInteger('remaining_paid_amount_minor');
            $table->bigInteger('converted_units_minor');
            $table->bigInteger('rounding_remainder_micro_rub');
            $table->string('evidence_reference', 512);
            $table->char('evidence_sha256', 64);
            $table->text('reason');
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'request_id'], 'eg_legacy_reconciliation_request_uq');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_legacy_reconciliations ADD CONSTRAINT eg_legacy_reconciliation_values_ck CHECK (
 remaining_paid_amount_minor BETWEEN 0 AND 1000000000000 AND converted_units_minor>=0 AND rounding_remainder_micro_rub>=0
 AND command_hash ~ '^[a-f0-9]{64}$' AND allocation_snapshot_hash ~ '^[a-f0-9]{64}$'
 AND purchase_snapshot_hash ~ '^[a-f0-9]{64}$' AND proof_hash ~ '^[a-f0-9]{64}$' AND evidence_sha256 ~ '^[a-f0-9]{64}$'
 AND jsonb_typeof(proof)='object' AND jsonb_typeof(sale_price_snapshot)='object');
CREATE FUNCTION eg_legacy_reconciliation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'estimate_generation.legacy_proof_immutable'; END IF;
 IF NOT EXISTS (SELECT 1 FROM organization_resource_allocations a WHERE a.id=NEW.allocation_id
   AND a.organization_id=NEW.organization_id AND a.limit_key='ai_estimates_month' AND a.source='paid_addon')
   OR NOT EXISTS (SELECT 1 FROM commercial_orders o WHERE o.id=NEW.commercial_order_id AND o.organization_id=NEW.organization_id)
 THEN RAISE EXCEPTION 'estimate_generation.legacy_proof_scope_invalid'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER eg_legacy_reconciliation_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_legacy_reconciliations
FOR EACH ROW EXECUTE FUNCTION eg_legacy_reconciliation_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_legacy_reconciliations');
        DB::statement('DROP FUNCTION IF EXISTS eg_legacy_reconciliation_guard()');
    }
};
