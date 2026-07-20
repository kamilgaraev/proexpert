<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql'
            || ! Schema::hasTable('legal_archive_document_version_operations')
        ) {
            return;
        }
        foreach (['document_fk', 'file_fk', 'version_fk', 'status_check', 'state_check'] as $suffix) {
            $name = "legal_archive_version_operations_{$suffix}";
            $constraint = DB::selectOne('SELECT convalidated FROM pg_constraint WHERE conname = ?', [$name]);
            if ($constraint === null) {
                throw new RuntimeException("legal_archive_version_operation_constraint_missing:{$name}");
            }
            if (! (bool) $constraint->convalidated) {
                DB::statement("ALTER TABLE legal_archive_document_version_operations VALIDATE CONSTRAINT {$name}");
            }
        }
    }

    public function down(): void {}
};
