<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->assertPostgreSql();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE report_runs IN ACCESS EXCLUSIVE MODE');
            $invalid = DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM report_runs WHERE scope_resource_ids IS NULL OR jsonb_typeof(scope_resource_ids) <> 'array' OR scope_resource_ids <> '[]'::jsonb",
            );
            if ((int) ($invalid->aggregate ?? 0) !== 0) {
                throw new \RuntimeException('report_scope_resources_cutover_requires_empty_source_scope');
            }

            DB::statement('ALTER TABLE report_runs ADD COLUMN scope_resources jsonb');
            DB::statement("UPDATE report_runs SET scope_resources = '[]'::jsonb");
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_scope_resources_array CHECK (jsonb_typeof(scope_resources) = 'array') NOT VALID");
            DB::statement('ALTER TABLE report_runs VALIDATE CONSTRAINT report_runs_scope_resources_array');
            DB::statement('ALTER TABLE report_runs ALTER COLUMN scope_resources SET NOT NULL');
            DB::statement('ALTER TABLE report_runs DROP COLUMN scope_resource_ids');
        }, 1);
    }

    public function down(): void
    {
        $this->assertPostgreSql();

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE report_runs IN ACCESS EXCLUSIVE MODE');
            $invalid = DB::selectOne(
                "SELECT COUNT(*) AS aggregate FROM report_runs WHERE scope_resources IS NULL OR jsonb_typeof(scope_resources) <> 'array' OR scope_resources <> '[]'::jsonb",
            );
            if ((int) ($invalid->aggregate ?? 0) !== 0) {
                throw new \RuntimeException('report_scope_resources_rollback_requires_empty_typed_scope');
            }

            DB::statement('ALTER TABLE report_runs ADD COLUMN scope_resource_ids jsonb');
            DB::statement("UPDATE report_runs SET scope_resource_ids = '[]'::jsonb");
            DB::statement("ALTER TABLE report_runs ADD CONSTRAINT report_runs_scope_resource_ids_array CHECK (jsonb_typeof(scope_resource_ids) = 'array') NOT VALID");
            DB::statement('ALTER TABLE report_runs VALIDATE CONSTRAINT report_runs_scope_resource_ids_array');
            DB::statement('ALTER TABLE report_runs ALTER COLUMN scope_resource_ids SET NOT NULL');
            DB::statement('ALTER TABLE report_runs DROP COLUMN scope_resources');
        }, 1);
    }

    private function assertPostgreSql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new \RuntimeException('report_scope_resources_cutover_requires_postgresql');
        }
    }
};
