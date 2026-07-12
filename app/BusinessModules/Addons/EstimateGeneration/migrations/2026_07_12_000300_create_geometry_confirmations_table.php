<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('estimate_generation_geometry_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('evidence_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('input_version', 71);
            $table->string('previous_model_version', 71);
            $table->string('source_class', 64);
            $table->string('reviewer_ref', 96);
            $table->timestampTz('confirmed_at');
            $table->jsonb('semantic_payload');
            $table->timestampsTz();
            $table->foreign(['evidence_id', 'organization_id', 'project_id', 'session_id'], 'eg_geometry_confirmation_evidence_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_evidence')->cascadeOnDelete();
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_geometry_confirmation_session_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->unique('evidence_id');
            $table->index(['organization_id', 'project_id', 'session_id', 'input_version'], 'eg_geometry_confirmation_scope_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_geometry_confirmations ADD CONSTRAINT eg_geometry_confirmation_version_ck CHECK (input_version ~ '^sha256:[a-f0-9]{64}$' AND previous_model_version ~ '^sha256:[a-f0-9]{64}$'), ADD CONSTRAINT eg_geometry_confirmation_source_ck CHECK (source_class = 'user_geometry_confirmation' AND reviewer_ref ~ '^user:[1-9][0-9]*$'), ADD CONSTRAINT eg_geometry_confirmation_payload_ck CHECK (jsonb_typeof(semantic_payload) = 'object' AND octet_length(semantic_payload::text) <= 262144)");
            DB::unprepared("CREATE FUNCTION eg_geometry_confirmation_immutable_guard() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP = 'UPDATE' THEN RAISE EXCEPTION 'estimate_generation.geometry_confirmation_immutable'; END IF; IF EXISTS (SELECT 1 FROM estimate_generation_sessions WHERE id = OLD.session_id AND organization_id = OLD.organization_id AND project_id = OLD.project_id) THEN RAISE EXCEPTION 'estimate_generation.geometry_confirmation_immutable'; END IF; RETURN OLD; END; $$; CREATE TRIGGER eg_geometry_confirmation_immutable_trg BEFORE UPDATE OR DELETE ON estimate_generation_geometry_confirmations FOR EACH ROW EXECUTE FUNCTION eg_geometry_confirmation_immutable_guard();");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS eg_geometry_confirmation_immutable_trg ON estimate_generation_geometry_confirmations');
            DB::statement('DROP FUNCTION IF EXISTS eg_geometry_confirmation_immutable_guard()');
        }
        Schema::dropIfExists('estimate_generation_geometry_confirmations');
    }
};
