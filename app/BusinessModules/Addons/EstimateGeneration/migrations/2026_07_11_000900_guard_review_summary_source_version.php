<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_review_summary_source_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE content_version text; source_version text; input_version text; snapshot_input_version text; classifier_version integer;
BEGIN
    IF NEW.draft_payload->'local_estimates' IS DISTINCT FROM OLD.draft_payload->'local_estimates'
       OR NEW.draft_payload->'source_input_version' IS DISTINCT FROM OLD.draft_payload->'source_input_version' THEN
        content_version := NEW.draft_payload #>> '{quality_summary,content_version}';
        source_version := NEW.draft_payload #>> '{quality_summary,review_items,source_version}';
        input_version := NEW.draft_payload #>> '{source_input_version}';
        snapshot_input_version := NEW.draft_payload #>> '{quality_summary,review_items,input_version}';
        classifier_version := COALESCE((NEW.draft_payload #>> '{quality_summary,review_items,classifier_version}')::integer, 0);
        IF content_version IS NULL
           OR (NEW.draft_payload->'local_estimates' IS DISTINCT FROM OLD.draft_payload->'local_estimates' AND content_version = COALESCE(OLD.draft_payload #>> '{quality_summary,content_version}', ''))
           OR content_version !~ '^sha256:[0-9a-f]{64}$'
           OR source_version IS DISTINCT FROM content_version
           OR input_version !~ '^sha256:[0-9a-f]{64}$'
           OR snapshot_input_version IS DISTINCT FROM input_version
           OR classifier_version <> 2 THEN
            RAISE EXCEPTION 'estimate_generation.review_summary_source_version_stale';
        END IF;
    END IF;
    RETURN NEW;
END; $$;
CREATE TRIGGER eg_review_summary_source_guard_trg BEFORE UPDATE OF draft_payload ON estimate_generation_sessions FOR EACH ROW EXECUTE FUNCTION eg_review_summary_source_guard();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS eg_review_summary_source_guard_trg ON estimate_generation_sessions');
            DB::statement('DROP FUNCTION IF EXISTS eg_review_summary_source_guard()');
        }
    }
};
