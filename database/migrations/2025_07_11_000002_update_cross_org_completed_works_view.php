<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS cross_org_completed_works');

        DB::statement(<<<SQL
CREATE VIEW cross_org_completed_works AS
SELECT
    cw.id                        AS id,
    cw.project_id                AS project_id,
    po.organization_id           AS child_organization_id,
    org.parent_organization_id   AS parent_organization_id,
    cw.work_type_id              AS work_type_id,
    cw.quantity                  AS quantity,
    cw.price                     AS price,
    cw.total_amount              AS total_amount,
    cw.completion_date           AS completion_date,
    cw.status                    AS status,
    cw.notes                     AS notes,
    cw.deleted_at                AS deleted_at,
    cw.created_at                AS created_at,
    cw.updated_at                AS updated_at
FROM completed_works cw
JOIN project_organization po
  ON po.project_id = cw.project_id
 AND po.organization_id = cw.organization_id
JOIN organizations org ON org.id = po.organization_id;
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS cross_org_completed_works');
    }
}; 