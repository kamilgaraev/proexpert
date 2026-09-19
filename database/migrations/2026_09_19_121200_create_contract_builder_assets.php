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
        Schema::create('contract_builder_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->constrained('contracts')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('name', 191);
            $table->string('mime_type', 127);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->text('storage_path');
            $table->string('request_key', 191);
            $table->char('fingerprint', 64);
            $table->timestampTz('created_at');
            $table->unique(['contract_id', 'organization_id', 'request_key'], 'contract_asset_request');
        });
        Schema::table('contract_builder_drafts', function (Blueprint $table): void {
            $table->jsonb('attachments')->nullable();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE contract_builder_assets ADD CONSTRAINT contract_asset_shape CHECK (
    kind IN ('attachment', 'evidence') AND size > 0 AND size <= 20971520
);
ALTER TABLE contract_builder_drafts ADD CONSTRAINT contract_draft_attachments_shape CHECK (
    attachments IS NULL OR jsonb_typeof(attachments) = 'array'
);
CREATE FUNCTION guard_contract_builder_asset() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'contract_builder_asset_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_builder_asset_guard BEFORE UPDATE OR DELETE ON contract_builder_assets
FOR EACH ROW EXECUTE FUNCTION guard_contract_builder_asset();
SQL);
    }

    public function down(): void
    {
        Schema::table('contract_builder_drafts', fn (Blueprint $table) => $table->dropColumn('attachments'));
        Schema::dropIfExists('contract_builder_assets');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_builder_asset();');
    }
};
