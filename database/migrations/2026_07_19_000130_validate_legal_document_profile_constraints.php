<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->backfillConfidentiality();
        $this->backfillOwnerPrincipal();
        foreach ($this->constraintsByTable() as $table => $constraints) {
            foreach ($constraints as $constraint) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            }
        }
        DB::statement('ALTER TABLE legal_archive_documents ALTER COLUMN confidentiality_level SET NOT NULL');
    }

    public function down(): void {}

    private function backfillConfidentiality(): void
    {
        do {
            $ids = DB::table('legal_archive_documents')
                ->whereNull('confidentiality_level')
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }
            DB::table('legal_archive_documents')
                ->whereIn('id', $ids)
                ->whereNull('confidentiality_level')->update(['confidentiality_level' => 'internal']);
        } while (true);
    }

    private function backfillOwnerPrincipal(): void
    {
        do {
            $ids = DB::table('legal_archive_documents')
                ->whereNull('owner_user_id')
                ->whereNotNull('created_by_user_id')
                ->orderBy('id')
                ->limit(1000)
                ->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }
            DB::table('legal_archive_documents')
                ->whereIn('id', $ids)
                ->whereNull('owner_user_id')
                ->update(['owner_user_id' => DB::raw('created_by_user_id')]);
        } while (true);
    }

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
