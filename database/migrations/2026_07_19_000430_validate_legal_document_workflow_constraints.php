<?php

declare(strict_types=1);

use App\Services\LegalArchive\Workflow\Schema\LegalWorkflowPostgresConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (LegalWorkflowPostgresConstraints::definitions() as $expected) {
            $constraint = DB::selectOne(
                <<<'SQL'
SELECT table_class.relname AS table_name,
       c.contype, c.condeferrable, c.condeferred, c.convalidated,
       pg_get_constraintdef(c.oid, true) AS definition
FROM pg_constraint c
JOIN pg_class table_class ON table_class.oid = c.conrelid
JOIN pg_namespace namespace ON namespace.oid = c.connamespace
WHERE namespace.nspname = current_schema() AND c.conname = ?
SQL,
                [$expected['name']],
            );
            if ($constraint === null || ! LegalWorkflowPostgresConstraints::matches($constraint, $expected)) {
                throw new RuntimeException("legal_workflow_constraint_descriptor_mismatch:{$expected['name']}");
            }
            if (! (bool) $constraint->convalidated) {
                DB::statement("ALTER TABLE {$expected['table']} VALIDATE CONSTRAINT {$expected['name']}");
            }
            $validated = DB::selectOne(
                'SELECT c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE c.connamespace = current_schema()::regnamespace AND c.conname = ? AND t.relname = ?',
                [$expected['name'], $expected['table']],
            );
            if ($validated === null || ! (bool) $validated->convalidated) {
                throw new RuntimeException("legal_workflow_constraint_validation_failed:{$expected['name']}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_workflow_migrations_are_forward_only');
    }
};
