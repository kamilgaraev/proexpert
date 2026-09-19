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
        Schema::table('contract_builder_instances', function (Blueprint $table): void {
            $table->unsignedBigInteger('effective_revision_id')->nullable();
            $table->foreign(['effective_revision_id', 'id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
        });
        Schema::create('contract_revision_activations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('contract_builder_instances')->restrictOnDelete();
            $table->unsignedBigInteger('revision_id');
            $table->unsignedBigInteger('previous_revision_id')->nullable();
            $table->foreign(['revision_id', 'instance_id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
            $table->foreign(['previous_revision_id', 'instance_id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('content_hash', 64);
            $table->text('basis');
            $table->date('effective_date');
            $table->jsonb('plan');
            $table->jsonb('previous_terms');
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->string('status', 20)->default('scheduled');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 64)->nullable();
            $table->jsonb('result')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->text('cancellation_basis')->nullable();
            $table->string('cancellation_key', 191)->nullable();
            $table->char('cancellation_fingerprint', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['instance_id', 'organization_id', 'request_key'], 'contract_activation_request');
            $table->index(['status', 'effective_date']);
        });
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX contract_activation_pending ON contract_revision_activations (instance_id) WHERE status IN ('scheduled', 'failed');
CREATE UNIQUE INDEX contract_activation_live_revision ON contract_revision_activations (revision_id) WHERE status <> 'cancelled';
ALTER TABLE contract_revision_activations ADD CONSTRAINT contract_activation_shape CHECK (
    status IN ('scheduled', 'failed', 'applied', 'cancelled') AND length(trim(basis)) > 0
    AND jsonb_typeof(plan) = 'object' AND jsonb_typeof(previous_terms) = 'object'
    AND ((status = 'applied' AND applied_at IS NOT NULL AND result IS NOT NULL AND last_error IS NULL)
        OR (status IN ('scheduled', 'failed', 'cancelled') AND applied_at IS NULL AND result IS NULL))
    AND ((status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancelled_organization_id IS NOT NULL
        AND cancellation_basis IS NOT NULL AND length(trim(cancellation_basis)) > 0 AND cancellation_key IS NOT NULL AND cancellation_fingerprint IS NOT NULL)
        OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancelled_organization_id IS NULL
            AND cancellation_basis IS NULL AND cancellation_key IS NULL AND cancellation_fingerprint IS NULL))
);
CREATE FUNCTION guard_contract_revision_activation() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_revision_activation_immutable'; END IF;
    IF OLD.status IN ('applied', 'cancelled') OR (to_jsonb(OLD) - ARRAY['status','attempts','last_error','result','applied_at','updated_at','cancelled_at','cancelled_by','cancelled_organization_id','cancellation_basis','cancellation_key','cancellation_fingerprint'])
        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status','attempts','last_error','result','applied_at','updated_at','cancelled_at','cancelled_by','cancelled_organization_id','cancellation_basis','cancellation_key','cancellation_fingerprint']) THEN
        RAISE EXCEPTION 'contract_revision_activation_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_revision_activation_guard BEFORE UPDATE OR DELETE ON contract_revision_activations
FOR EACH ROW EXECUTE FUNCTION guard_contract_revision_activation();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revision_activations');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_revision_activation();');
        Schema::table('contract_builder_instances', function (Blueprint $table): void {
            $table->dropForeign(['effective_revision_id', 'id']);
            $table->dropColumn('effective_revision_id');
        });
    }
};
