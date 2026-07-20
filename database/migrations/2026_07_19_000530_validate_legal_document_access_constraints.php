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
        foreach ([
            'legal_documents_source_type_check' => 'legal_archive_documents',
            'legal_document_party_snapshot_sets_version_fk' => 'legal_document_party_snapshot_sets',
            'legal_document_party_snapshot_sets_captured_by_fk' => 'legal_document_party_snapshot_sets',
            'legal_document_parties_document_fk' => 'legal_document_parties',
            'legal_document_parties_snapshot_set_fk' => 'legal_document_parties',
            'legal_document_parties_organization_fk' => 'legal_document_parties',
            'legal_document_parties_party_organization_fk' => 'legal_document_parties',
            'legal_document_parties_counterparty_fk' => 'legal_document_parties',
            'legal_document_parties_role_check' => 'legal_document_parties',
            'legal_document_parties_source_check' => 'legal_document_parties',
            'legal_document_access_document_fk' => 'legal_document_access_grants',
            'legal_document_access_owner_organization_fk' => 'legal_document_access_grants',
            'legal_document_access_subject_organization_fk' => 'legal_document_access_grants',
            'legal_document_access_subject_membership_fk' => 'legal_document_access_grants',
            'legal_document_access_subject_check' => 'legal_document_access_grants',
            'legal_document_access_granted_by_fk' => 'legal_document_access_grants',
            'legal_document_access_revoked_by_fk' => 'legal_document_access_grants',
            'legal_document_access_abilities_check' => 'legal_document_access_grants',
            'legal_document_access_revocation_check' => 'legal_document_access_grants',
            'legal_document_access_expiry_check' => 'legal_document_access_grants',
            'legal_document_comments_document_fk' => 'legal_document_comments',
            'legal_document_comments_version_fk' => 'legal_document_comments',
            'legal_document_comments_author_fk' => 'legal_document_comments',
            'legal_document_comments_resolved_by_fk' => 'legal_document_comments',
            'legal_document_comments_body_check' => 'legal_document_comments',
            'legal_document_comments_page_check' => 'legal_document_comments',
            'legal_document_comments_anchor_check' => 'legal_document_comments',
            'legal_document_comments_visibility_check' => 'legal_document_comments',
            'legal_document_comments_resolution_check' => 'legal_document_comments',
            'legal_document_comments_hash_check' => 'legal_document_comments',
        ] as $name => $table) {
            DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_access_migrations_are_forward_only');
    }
};
