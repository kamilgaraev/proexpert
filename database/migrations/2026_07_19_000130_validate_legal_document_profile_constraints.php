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

        foreach ($this->constraintsByTable() as $table => $constraints) {
            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            }
        }
    }

    public function down(): void {}

    /** @return array<string, list<string>> */
    private function constraintsByTable(): array
    {
        return [
            'legal_archive_document_type_profiles' => [
                'legal_doc_profiles_organization_fk',
                'legal_doc_profiles_lock_version_check',
                'legal_doc_profiles_confidentiality_check',
            ],
            'legal_archive_documents' => [
                'legal_docs_owner_user_fk',
                'legal_docs_responsible_user_fk',
                'legal_docs_lifecycle_status_check',
                'legal_docs_approval_status_check',
                'legal_docs_signature_status_check',
                'legal_docs_confidentiality_check',
                'legal_docs_lock_version_check',
                'legal_docs_source_identity_check',
                'legal_docs_current_primary_version_fk',
            ],
        ];
    }
};
