<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX wvs_one_approved_revision
ON work_volume_statements (organization_id, project_id, statement_key)
WHERE status = 'approved';

CREATE FUNCTION wvs_protect_approved_history() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.review_history IS DISTINCT FROM OLD.review_history THEN
        IF jsonb_typeof(NEW.review_history) <> 'array'
           OR jsonb_array_length(NEW.review_history) <> jsonb_array_length(OLD.review_history) + 1
           OR (NEW.review_history - jsonb_array_length(OLD.review_history)) IS DISTINCT FROM OLD.review_history THEN
            RAISE EXCEPTION 'wvs_review_history_immutable' USING ERRCODE = '55000';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' AND jsonb_array_length(OLD.review_history) > 0 THEN
        RAISE EXCEPTION 'wvs_review_history_immutable' USING ERRCODE = '55000';
    END IF;
    IF OLD.status = 'review' THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'wvs_review_content_immutable' USING ERRCODE = '55000';
        END IF;
        IF (to_jsonb(NEW) - ARRAY['status', 'updated_at', 'approved_at', 'approved_by_user_id', 'review_history']) IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['status', 'updated_at', 'approved_at', 'approved_by_user_id', 'review_history'])
           OR NEW.status NOT IN ('review', 'approved', 'draft') THEN
            RAISE EXCEPTION 'wvs_review_content_immutable' USING ERRCODE = '55000';
        END IF;
    END IF;
    IF OLD.status IN ('approved', 'replaced') THEN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'wvs_approved_history_immutable' USING ERRCODE = '55000';
        END IF;
        IF (to_jsonb(NEW) - ARRAY['status', 'updated_at']) IS DISTINCT FROM
           (to_jsonb(OLD) - ARRAY['status', 'updated_at'])
           OR NOT (NEW.status = OLD.status OR (OLD.status = 'approved' AND NEW.status = 'replaced')) THEN
            RAISE EXCEPTION 'wvs_approved_history_immutable' USING ERRCODE = '55000';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER wvs_protect_approved_history
BEFORE UPDATE OR DELETE ON work_volume_statements
FOR EACH ROW EXECUTE FUNCTION wvs_protect_approved_history();

CREATE FUNCTION wvsl_protect_approved_history() RETURNS trigger AS $$
DECLARE
    source_id bigint;
    target_id bigint;
BEGIN
    IF TG_OP <> 'INSERT' THEN source_id := OLD.statement_id; END IF;
    IF TG_OP <> 'DELETE' THEN target_id := NEW.statement_id; END IF;
    PERFORM id FROM work_volume_statements
    WHERE id IN (source_id, target_id) ORDER BY id FOR UPDATE;
    IF EXISTS (SELECT 1 FROM work_volume_statements
        WHERE id IN (source_id, target_id) AND status IN ('review', 'approved', 'replaced')) THEN
        RAISE EXCEPTION 'wvsl_approved_history_immutable' USING ERRCODE = '55000';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER wvsl_protect_approved_history
BEFORE INSERT OR UPDATE OR DELETE ON work_volume_statement_lines
FOR EACH ROW EXECUTE FUNCTION wvsl_protect_approved_history();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS wvsl_protect_approved_history ON work_volume_statement_lines;
DROP FUNCTION IF EXISTS wvsl_protect_approved_history();
DROP TRIGGER IF EXISTS wvs_protect_approved_history ON work_volume_statements;
DROP FUNCTION IF EXISTS wvs_protect_approved_history();
DROP INDEX IF EXISTS wvs_one_approved_revision;
SQL);
    }
};
