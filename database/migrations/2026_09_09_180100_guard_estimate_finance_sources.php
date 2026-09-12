<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_estimate_finance_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE scoped_estimate bigint;
DECLARE protected_change boolean;
BEGIN
    IF TG_TABLE_NAME = 'contract_estimate_items' THEN
        IF TG_OP = 'DELETE' THEN scoped_estimate := OLD.estimate_id; ELSE scoped_estimate := NEW.estimate_id; END IF;
        UPDATE estimates SET finance_revision = finance_revision + 1 WHERE id = scoped_estimate;
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF TG_TABLE_NAME = 'estimate_items' THEN
            scoped_estimate := NEW.estimate_id;
        ELSE
            SELECT estimate_id INTO scoped_estimate FROM estimate_items WHERE id = NEW.estimate_item_id;
        END IF;
        UPDATE estimates SET finance_revision = finance_revision + 1 WHERE id = scoped_estimate;
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME = 'estimate_item_resources' THEN
        SELECT estimate_id INTO scoped_estimate FROM estimate_items WHERE id = OLD.estimate_item_id;
        protected_change := TG_OP = 'DELETE';
        IF TG_OP = 'UPDATE' THEN
            protected_change := NEW.measurement_unit_id IS DISTINCT FROM OLD.measurement_unit_id OR NEW.estimate_item_id IS DISTINCT FROM OLD.estimate_item_id;
        END IF;
        PERFORM id FROM estimates WHERE id = scoped_estimate FOR UPDATE;
        IF protected_change AND EXISTS (SELECT 1 FROM estimate_finance_allocations WHERE resource_id = OLD.id) THEN
            RAISE EXCEPTION 'estimate_finance_source_is_allocated' USING ERRCODE = '23503';
        END IF;
    ELSIF TG_TABLE_NAME = 'estimate_items' THEN
        scoped_estimate := OLD.estimate_id;
        PERFORM id FROM estimates WHERE id = scoped_estimate FOR UPDATE;
        protected_change := TG_OP = 'DELETE';
        IF TG_OP = 'UPDATE' THEN
            protected_change := NEW.deleted_at IS DISTINCT FROM OLD.deleted_at OR NEW.measurement_unit_id IS DISTINCT FROM OLD.measurement_unit_id
                OR NEW.estimate_id IS DISTINCT FROM OLD.estimate_id OR NEW.parent_work_id IS DISTINCT FROM OLD.parent_work_id;
        END IF;
        IF protected_change AND EXISTS (
            WITH RECURSIVE descendants AS (
                SELECT OLD.id AS id UNION SELECT i.id FROM estimate_items i JOIN descendants d ON i.parent_work_id = d.id
            )
            SELECT 1 FROM descendants d WHERE EXISTS (SELECT 1 FROM contract_estimate_items WHERE estimate_item_id = d.id)
                OR EXISTS (SELECT 1 FROM estimate_finance_allocations WHERE estimate_item_id = d.id)
        ) THEN
            RAISE EXCEPTION 'estimate_finance_source_is_allocated' USING ERRCODE = '23503';
        END IF;
    ELSE
        scoped_estimate := OLD.id;
        protected_change := TG_OP = 'DELETE';
        IF TG_OP = 'UPDATE' THEN
            protected_change := NEW.deleted_at IS DISTINCT FROM OLD.deleted_at OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
                OR NEW.project_id IS DISTINCT FROM OLD.project_id;
        END IF;
        IF protected_change AND (EXISTS (SELECT 1 FROM contract_estimate_items WHERE estimate_id = OLD.id)
            OR EXISTS (SELECT 1 FROM estimate_finance_allocations WHERE estimate_id = OLD.id)) THEN
            RAISE EXCEPTION 'estimate_finance_source_is_allocated' USING ERRCODE = '23503';
        END IF;
    END IF;
    IF TG_TABLE_NAME <> 'estimates' THEN
        UPDATE estimates SET finance_revision = finance_revision + 1 WHERE id = scoped_estimate;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER guard_estimate_finance_items BEFORE INSERT OR UPDATE OR DELETE ON estimate_items FOR EACH ROW EXECUTE FUNCTION guard_estimate_finance_source();
CREATE TRIGGER guard_estimate_finance_resources BEFORE INSERT OR UPDATE OR DELETE ON estimate_item_resources FOR EACH ROW EXECUTE FUNCTION guard_estimate_finance_source();
CREATE TRIGGER guard_estimate_finance_estimates BEFORE UPDATE OR DELETE ON estimates FOR EACH ROW EXECUTE FUNCTION guard_estimate_finance_source();
CREATE TRIGGER guard_estimate_finance_links BEFORE INSERT OR UPDATE OR DELETE ON contract_estimate_items FOR EACH ROW EXECUTE FUNCTION guard_estimate_finance_source();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS guard_estimate_finance_items ON estimate_items; DROP TRIGGER IF EXISTS guard_estimate_finance_resources ON estimate_item_resources; DROP TRIGGER IF EXISTS guard_estimate_finance_estimates ON estimates; DROP TRIGGER IF EXISTS guard_estimate_finance_links ON contract_estimate_items; DROP FUNCTION IF EXISTS guard_estimate_finance_source();');
    }
};
