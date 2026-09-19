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
        Schema::create('contract_revision_payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('contract_builder_revisions')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('activation_id')->constrained('contract_revision_activations')->restrictOnDelete();
            $table->char('content_hash', 64);
            $table->jsonb('conditions');
            $table->jsonb('bases');
            $table->boolean('is_current')->default(true);
            $table->timestampTz('created_at');
            $table->unique(['revision_id', 'organization_id'], 'contract_payment_plan_revision_org');
        });
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX contract_payment_plan_current ON contract_revision_payment_plans (contract_id, organization_id) WHERE is_current;
CREATE FUNCTION guard_contract_revision_payment_plan() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'contract_revision_payment_plan_immutable'; END IF;
    IF OLD.is_current IS FALSE OR NEW.is_current IS TRUE
        OR (to_jsonb(OLD) - 'is_current') IS DISTINCT FROM (to_jsonb(NEW) - 'is_current') THEN
        RAISE EXCEPTION 'contract_revision_payment_plan_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_revision_payment_plan_guard BEFORE UPDATE OR DELETE ON contract_revision_payment_plans
FOR EACH ROW EXECUTE FUNCTION guard_contract_revision_payment_plan();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revision_payment_plans');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_revision_payment_plan();');
    }
};
