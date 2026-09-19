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
        Schema::create('contract_revision_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('contract_builder_instances')->restrictOnDelete();
            $table->unsignedBigInteger('revision_id');
            $table->string('side', 8);
            $table->foreignId('party_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->foreignId('recorded_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('content_hash', 64);
            $table->text('basis')->nullable();
            $table->jsonb('proof')->nullable();
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->timestampTz('created_at');
            $table->unique(['revision_id', 'side'], 'contract_revision_confirmation_side');
            $table->unique(['instance_id', 'recorded_organization_id', 'request_key'], 'contract_confirmation_request');
            $table->foreign(['revision_id', 'instance_id'], 'contract_confirmation_revision')->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE contract_revision_confirmations ADD CONSTRAINT contract_confirmation_shape CHECK (
    side IN ('first', 'second') AND (
      (kind = 'connected' AND party_organization_id IS NOT NULL AND party_organization_id = recorded_organization_id AND proof IS NULL)
      OR (kind = 'external' AND party_organization_id IS NULL AND basis IS NOT NULL AND length(trim(basis)) > 0 AND proof IS NOT NULL AND jsonb_typeof(proof) = 'object')
    )
);
CREATE FUNCTION guard_contract_revision_confirmation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'contract_revision_confirmation_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_revision_confirmation_guard BEFORE UPDATE OR DELETE ON contract_revision_confirmations
FOR EACH ROW EXECUTE FUNCTION guard_contract_revision_confirmation();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_revision_confirmations');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_revision_confirmation();');
    }
};
