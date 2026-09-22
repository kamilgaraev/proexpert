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
        Schema::table('work_volume_statements', function (Blueprint $table): void {
            $table->unsignedInteger('draft_version')->default(1)->after('version');
        });

        Schema::create('work_volume_statement_draft_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('statement_id')->constrained('work_volume_statements')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('expected_draft_version');
            $table->unsignedInteger('resulting_draft_version');
            $table->string('operation_key', 128);
            $table->string('operation_hash', 64);
            $table->jsonb('result_snapshot');
            $table->timestampsTz();
            $table->unique(['statement_id', 'actor_id', 'operation_key'], 'wvse_statement_actor_operation');
        });

        DB::unprepared(<<<'SQL'
CREATE FUNCTION wvse_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'wvs_draft_edit_immutable' USING ERRCODE = '55000';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER wvse_immutable BEFORE UPDATE OR DELETE ON work_volume_statement_draft_edits
FOR EACH ROW EXECUTE FUNCTION wvse_immutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wvse_immutable ON work_volume_statement_draft_edits');
        DB::unprepared('DROP FUNCTION IF EXISTS wvse_immutable()');
        Schema::dropIfExists('work_volume_statement_draft_edits');
        Schema::table('work_volume_statements', function (Blueprint $table): void {
            $table->dropColumn('draft_version');
        });
    }
};
