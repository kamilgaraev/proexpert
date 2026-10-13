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
            throw new RuntimeException('Legacy estimator refund resolution requires PostgreSQL.');
        }
        Schema::create('estimate_generation_legacy_refund_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained('estimate_generation_legacy_reconciliations')->restrictOnDelete();
            $table->foreignId('ai_credit_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('commercial_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('system_admin_id')->constrained('system_admins')->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('command_hash', 64);
            $table->char('snapshot_hash', 64);
            $table->char('proof_hash', 64);
            $table->jsonb('proof');
            $table->bigInteger('cumulative_refund_amount_minor');
            $table->bigInteger('remaining_paid_amount_minor');
            $table->bigInteger('remaining_units_minor');
            $table->bigInteger('revoked_units_minor');
            $table->bigInteger('rounding_remainder_micro_rub');
            $table->timestampTz('created_at');
            $table->unique(['organization_id', 'request_id'], 'eg_legacy_refund_resolution_request_uq');
            $table->unique(['ai_credit_lot_id', 'cumulative_refund_amount_minor'], 'eg_legacy_refund_resolution_version_uq');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_legacy_refund_resolutions ADD CONSTRAINT eg_legacy_refund_resolution_values_ck CHECK (
 cumulative_refund_amount_minor>0 AND remaining_paid_amount_minor BETWEEN 0 AND 1000000000000
 AND remaining_units_minor>=0 AND revoked_units_minor>=0 AND rounding_remainder_micro_rub>=0
 AND command_hash ~ '^[a-f0-9]{64}$' AND snapshot_hash ~ '^[a-f0-9]{64}$' AND proof_hash ~ '^[a-f0-9]{64}$'
 AND jsonb_typeof(proof)='object');
CREATE FUNCTION eg_legacy_refund_resolution_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'estimate_generation.legacy_refund_resolution_immutable'; END IF;
 IF NOT EXISTS (SELECT 1 FROM estimate_generation_legacy_reconciliations r
   JOIN ai_credit_lots l ON l.id=NEW.ai_credit_lot_id AND l.organization_id=r.organization_id AND l.commercial_order_id=r.commercial_order_id
   JOIN commercial_payments p ON p.id=NEW.commercial_payment_id AND p.commercial_order_id=r.commercial_order_id
   WHERE r.id=NEW.reconciliation_id AND r.organization_id=NEW.organization_id)
 THEN RAISE EXCEPTION 'estimate_generation.legacy_refund_resolution_scope_invalid'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER eg_legacy_refund_resolution_guard_trg BEFORE INSERT OR UPDATE OR DELETE ON estimate_generation_legacy_refund_resolutions
FOR EACH ROW EXECUTE FUNCTION eg_legacy_refund_resolution_guard();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_generation_legacy_refund_resolutions');
        DB::statement('DROP FUNCTION IF EXISTS eg_legacy_refund_resolution_guard()');
    }
};
