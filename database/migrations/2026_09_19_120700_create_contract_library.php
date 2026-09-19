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
        Schema::create('contract_library_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->string('creation_key', 191);
            $table->char('creation_fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['id', 'organization_id']);
            $table->unique(['organization_id', 'creation_key']);
            $table->index(['organization_id', 'kind', 'is_archived']);
        });
        Schema::create('contract_library_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('item_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedInteger('version_number');
            $table->string('title', 255);
            $table->jsonb('content');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('created_at');
            $table->string('request_key', 191);
            $table->char('request_fingerprint', 64);
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('contract_library_items')->restrictOnDelete();
            $table->unique(['item_id', 'version_number']);
            $table->unique(['item_id', 'request_key']);
            $table->unique(['id', 'organization_id']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE contract_library_items ADD CONSTRAINT contract_library_item_kind CHECK (kind IN ('template', 'block', 'variable'));
ALTER TABLE contract_library_items ADD CONSTRAINT contract_library_item_version CHECK (lock_version > 0);
ALTER TABLE contract_library_versions ADD CONSTRAINT contract_library_version_state CHECK (
    version_number > 0 AND jsonb_typeof(content) = 'object' AND
    ((status = 'draft' AND published_at IS NULL AND published_by IS NULL)
    OR (status = 'published' AND published_at IS NOT NULL AND published_by IS NOT NULL))
);
CREATE FUNCTION guard_contract_library_version() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'contract_library_version_immutable';
    END IF;
    IF OLD.status <> 'draft' OR NEW.status <> 'published'
        OR (to_jsonb(OLD) - ARRAY['status', 'published_at', 'published_by'])
        IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status', 'published_at', 'published_by']) THEN
        RAISE EXCEPTION 'contract_library_version_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_library_version_guard BEFORE UPDATE OR DELETE ON contract_library_versions
FOR EACH ROW EXECUTE FUNCTION guard_contract_library_version();
CREATE FUNCTION guard_contract_library_identity() RETURNS trigger AS $$
BEGIN
    IF (OLD.id, OLD.organization_id, OLD.kind, OLD.creation_key, OLD.creation_fingerprint)
        IS DISTINCT FROM (NEW.id, NEW.organization_id, NEW.kind, NEW.creation_key, NEW.creation_fingerprint) THEN
        RAISE EXCEPTION 'contract_library_identity_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_library_identity_guard BEFORE UPDATE ON contract_library_items
FOR EACH ROW EXECUTE FUNCTION guard_contract_library_identity();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_library_versions');
        Schema::dropIfExists('contract_library_items');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_library_version(); DROP FUNCTION IF EXISTS guard_contract_library_identity();');
    }
};
