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
        DB::statement('ALTER TABLE estimate_generation_sessions ADD CONSTRAINT eg_session_evaluation_scope_uk UNIQUE (id,organization_id,project_id)');
        Schema::create('estimate_generation_evaluation_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('revision');
            $table->unsignedInteger('input_state_version');
            $table->uuid('operation_id');
            $table->char('input_hash', 64);
            $table->char('content_hash', 64);
            $table->string('result_class', 32);
            $table->string('profile_version', 64);
            $table->jsonb('result');
            $table->timestampTz('created_at');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_evaluation_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->restrictOnDelete();
            $table->unique(['organization_id', 'session_id', 'revision'], 'eg_evaluation_revision_uk');
            $table->unique(['organization_id', 'session_id', 'operation_id'], 'eg_evaluation_operation_uk');
        });
        DB::statement("ALTER TABLE estimate_generation_evaluation_revisions ADD CONSTRAINT eg_evaluation_class_ck CHECK (result_class IN ('scenario_estimate','refined_estimate','verified_scope_estimate'))");
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION eg_evaluation_revision_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'evaluation_revision_immutable'; END $$;
            CREATE TRIGGER eg_evaluation_revision_immutable BEFORE UPDATE OR DELETE ON estimate_generation_evaluation_revisions
            FOR EACH ROW EXECUTE FUNCTION eg_evaluation_revision_immutable();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('estimate_generation_evaluation_revisions')->exists()) {
            throw new RuntimeException('evaluation_revision_history_must_be_preserved');
        }
        Schema::drop('estimate_generation_evaluation_revisions');
        DB::statement('DROP FUNCTION eg_evaluation_revision_immutable()');
        DB::statement('ALTER TABLE estimate_generation_sessions DROP CONSTRAINT eg_session_evaluation_scope_uk');
    }
};
