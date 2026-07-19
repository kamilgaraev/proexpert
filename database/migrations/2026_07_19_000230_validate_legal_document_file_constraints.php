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

        $this->validate('legal_archive_document_files', 'legal_archive_document_files_document_fk');
        $this->validate('legal_archive_document_versions', 'legal_archive_versions_document_file_fk');
        $this->validate('legal_archive_document_files', 'legal_archive_document_files_current_fk');
        $this->validate('legal_archive_document_versions', 'legal_archive_versions_processing_status_check');
    }

    public function down(): void {}

    private function validate(string $table, string $constraint): void
    {
        $pending = DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ? AND convalidated = false',
            [$constraint],
        );
        if ($pending !== null) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
        }
    }
};
