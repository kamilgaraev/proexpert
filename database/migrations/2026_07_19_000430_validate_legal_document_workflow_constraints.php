<?php

declare(strict_types=1);

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
        foreach (DB::select(
            "SELECT c.conname, t.relname AS table_name FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid WHERE c.connamespace = current_schema()::regnamespace AND c.conname LIKE 'legal_workflow_%' AND c.convalidated = false ORDER BY c.conname"
        ) as $constraint) {
            DB::statement("ALTER TABLE {$constraint->table_name} VALIDATE CONSTRAINT {$constraint->conname}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_workflow_migrations_are_forward_only');
    }
};
