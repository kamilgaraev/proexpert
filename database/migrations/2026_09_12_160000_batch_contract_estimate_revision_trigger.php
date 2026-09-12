<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS guard_estimate_finance_links ON contract_estimate_items;
CREATE FUNCTION bump_contract_estimate_finance_revision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE estimate_ids bigint[];
DECLARE scoped_estimate bigint;
BEGIN
    IF TG_OP = 'INSERT' THEN
        SELECT array_agg(DISTINCT estimate_id ORDER BY estimate_id) INTO estimate_ids FROM new_finance_links;
    ELSIF TG_OP = 'DELETE' THEN
        SELECT array_agg(DISTINCT estimate_id ORDER BY estimate_id) INTO estimate_ids FROM old_finance_links;
    ELSE
        SELECT array_agg(DISTINCT estimate_id ORDER BY estimate_id) INTO estimate_ids
        FROM (SELECT estimate_id FROM new_finance_links UNION SELECT estimate_id FROM old_finance_links) AS affected;
    END IF;
    FOREACH scoped_estimate IN ARRAY COALESCE(estimate_ids, ARRAY[]::bigint[]) LOOP
        UPDATE estimates SET finance_revision = finance_revision + 1 WHERE id = scoped_estimate;
    END LOOP;
    RETURN NULL;
END;
$$;
CREATE TRIGGER bump_contract_estimate_finance_insert AFTER INSERT ON contract_estimate_items
REFERENCING NEW TABLE AS new_finance_links FOR EACH STATEMENT EXECUTE FUNCTION bump_contract_estimate_finance_revision();
CREATE TRIGGER bump_contract_estimate_finance_update AFTER UPDATE ON contract_estimate_items
REFERENCING NEW TABLE AS new_finance_links OLD TABLE AS old_finance_links FOR EACH STATEMENT EXECUTE FUNCTION bump_contract_estimate_finance_revision();
CREATE TRIGGER bump_contract_estimate_finance_delete AFTER DELETE ON contract_estimate_items
REFERENCING OLD TABLE AS old_finance_links FOR EACH STATEMENT EXECUTE FUNCTION bump_contract_estimate_finance_revision();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS bump_contract_estimate_finance_insert ON contract_estimate_items;
DROP TRIGGER IF EXISTS bump_contract_estimate_finance_update ON contract_estimate_items;
DROP TRIGGER IF EXISTS bump_contract_estimate_finance_delete ON contract_estimate_items;
DROP FUNCTION IF EXISTS bump_contract_estimate_finance_revision();
CREATE TRIGGER guard_estimate_finance_links BEFORE INSERT OR UPDATE OR DELETE ON contract_estimate_items
FOR EACH ROW EXECUTE FUNCTION guard_estimate_finance_source();
SQL);
    }
};
