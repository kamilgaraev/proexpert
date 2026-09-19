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
        Schema::create('contract_builder_instances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('contract_builder_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->constrained('contract_builder_instances')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->unsignedBigInteger('base_revision_id')->nullable();
            $table->foreignId('template_version_id')->constrained('contract_library_versions')->restrictOnDelete();
            $table->foreignId('author_organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->jsonb('document');
            $table->jsonb('definitions');
            $table->jsonb('blocks')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('values');
            $table->jsonb('parties');
            $table->jsonb('attachments');
            $table->char('content_hash', 64);
            $table->string('request_key', 191);
            $table->char('request_fingerprint', 64);
            $table->timestampTz('created_at');
            $table->unique(['id', 'instance_id']);
            $table->unique(['instance_id', 'revision_number']);
            $table->unique(['instance_id', 'request_key']);
            $table->foreign(['base_revision_id', 'instance_id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
        });
        Schema::table('contract_builder_instances', function (Blueprint $table): void {
            $table->foreign(['current_revision_id', 'id'])->references(['id', 'instance_id'])->on('contract_builder_revisions')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE contract_builder_instances ADD CONSTRAINT contract_builder_lock_positive CHECK (lock_version > 0);
ALTER TABLE contract_builder_revisions ADD CONSTRAINT contract_builder_revision_shape CHECK (
    revision_number > 0 AND ((revision_number = 1 AND base_revision_id IS NULL) OR (revision_number > 1 AND base_revision_id IS NOT NULL))
    AND jsonb_typeof(document) = 'object' AND jsonb_typeof(definitions) = 'object' AND jsonb_typeof(values) = 'object' AND jsonb_typeof(blocks) = 'object'
    AND jsonb_typeof(parties) = 'array' AND jsonb_array_length(parties) = 2 AND jsonb_typeof(attachments) = 'array'
);
CREATE FUNCTION guard_contract_builder_revision() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'contract_builder_revision_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_builder_revision_guard BEFORE UPDATE OR DELETE ON contract_builder_revisions
FOR EACH ROW EXECUTE FUNCTION guard_contract_builder_revision();
CREATE FUNCTION guard_contract_builder_instance() RETURNS trigger AS $$
BEGIN
    IF OLD.id IS DISTINCT FROM NEW.id OR OLD.contract_id IS DISTINCT FROM NEW.contract_id THEN
        RAISE EXCEPTION 'contract_builder_instance_identity_immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_builder_instance_guard BEFORE UPDATE ON contract_builder_instances
FOR EACH ROW EXECUTE FUNCTION guard_contract_builder_instance();
SQL);
    }

    public function down(): void
    {
        Schema::table('contract_builder_instances', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id', 'id']);
        });
        Schema::dropIfExists('contract_builder_revisions');
        Schema::dropIfExists('contract_builder_instances');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_builder_revision(); DROP FUNCTION IF EXISTS guard_contract_builder_instance();');
    }
};
