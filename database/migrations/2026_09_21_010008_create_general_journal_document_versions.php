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
        Schema::create('general_journal_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_id')->constrained('construction_journals')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('operation_key', 128);
            $table->char('request_fingerprint', 64);
            $table->string('template_version', 64);
            $table->jsonb('source_snapshot');
            $table->char('snapshot_hash', 64);
            $table->foreignId('previous_version_id')->nullable()->constrained('general_journal_document_versions')->restrictOnDelete();
            $table->text('correction_reason')->nullable();
            $table->timestampTz('created_at');
            $table->unique(['journal_id', 'revision'], 'general_journal_revision_unique');
            $table->unique(['journal_id', 'created_by_user_id', 'operation_key'], 'general_journal_operation_unique');
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION prevent_general_journal_version_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'general_journal_version_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER general_journal_version_immutable
BEFORE UPDATE OR DELETE ON general_journal_document_versions
FOR EACH ROW EXECUTE FUNCTION prevent_general_journal_version_mutation();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('general_journal_document_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_general_journal_version_mutation()');
    }
};
