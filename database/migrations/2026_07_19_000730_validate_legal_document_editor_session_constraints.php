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
        $descriptorMigration = require __DIR__.'/2026_07_19_000710_create_legal_document_editor_session_indexes.php';
        $descriptorMigration->up();
        foreach (DB::select("SELECT c.conname, c.convalidated FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_document_editor_sessions' AND c.conname LIKE 'legal_editor_sessions_%'") as $constraint) {
            if (! (bool) $constraint->convalidated) {
                DB::statement("ALTER TABLE legal_document_editor_sessions VALIDATE CONSTRAINT {$constraint->conname}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_session_validation_forward_only');
    }
};
