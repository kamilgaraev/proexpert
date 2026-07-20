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
        $descriptorMigration = require __DIR__.'/2026_07_19_000620_add_legal_document_signature_constraints.php';
        $descriptorMigration->up();
        foreach ($this->manifest() as $name => $table) {
            $constraint = DB::selectOne('SELECT c.conrelid::regclass::text AS table_name, c.convalidated::integer AS validated FROM pg_constraint c JOIN pg_namespace n ON n.oid=(SELECT relnamespace FROM pg_class WHERE oid=c.conrelid) WHERE n.nspname=current_schema() AND c.conname=?', [$name]);
            if ($constraint === null || $constraint->table_name !== $table) {
                throw new RuntimeException("legal_signature_constraint_manifest_mismatch:{$name}");
            }
            if (! (bool) $constraint->validated) {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
            }
        }
        DB::statement('ALTER TABLE legal_document_access_grants VALIDATE CONSTRAINT legal_document_access_abilities_check');
        foreach ($this->manifest() as $name => $table) {
            $validated = DB::selectOne('SELECT c.convalidated::integer AS validated FROM pg_constraint c JOIN pg_namespace n ON n.oid=(SELECT relnamespace FROM pg_class WHERE oid=c.conrelid) WHERE n.nspname=current_schema() AND c.conname=? AND c.conrelid=?::regclass', [$name, $table]);
            if ($validated === null || ! (bool) $validated->validated) {
                throw new RuntimeException("legal_signature_constraint_not_validated:{$name}");
            }
        }
        $access = DB::selectOne("SELECT c.convalidated::integer AS validated, pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_document_access_grants' AND c.conname='legal_document_access_abilities_check'");
        if ($access === null || ! (bool) $access->validated
            || ! str_contains((string) $access->definition, 'request_signature')
            || ! str_contains((string) $access->definition, 'verify_signature')) {
            throw new RuntimeException('legal_document_access_abilities_validation_failed');
        }
        $descriptorMigration->up();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_signature_migrations_are_forward_only');
    }

    private function manifest(): array
    {
        $request = ['organization_fk', 'document_fk', 'version_fk', 'party_fk', 'replaces_fk', 'user_fk', 'method_check', 'status_check', 'hash_check', 'replacement_check', 'signers_check', 'provider_check', 'callback_check', 'time_check'];
        $signature = ['request_fk', 'organization_fk', 'version_fk', 'party_fk', 'user_fk', 'method_check', 'hash_check', 'evidence_check', 'revocation_check', 'time_check', 'typed_evidence_check'];
        $verification = ['signature_fk', 'organization_fk', 'user_fk', 'status_check', 'hash_check', 'revocation_check'];
        $operation = ['request_fk', 'supersedes_fk', 'status_check', 'hash_check', 'lease_check'];
        $manifest = [];
        foreach ($request as $suffix) {
            $manifest["legal_signature_requests_{$suffix}"] = 'legal_signature_requests';
        }
        foreach ($signature as $suffix) {
            $manifest["legal_document_signatures_{$suffix}"] = 'legal_document_signatures';
        }
        foreach ($verification as $suffix) {
            $manifest["legal_signature_verifications_{$suffix}"] = 'legal_signature_verifications';
        }
        foreach ($operation as $suffix) {
            $manifest["legal_signature_provider_operations_{$suffix}"] = 'legal_signature_provider_operations';
        }
        foreach (['document_fk', 'version_fk', 'hash_check', 'binding_check', 'lease_check', 'terminal_check'] as $suffix) {
            $manifest["legal_signature_cleanup_debts_{$suffix}"] = 'legal_archive_file_cleanup_debts';
        }
        foreach (['request_fk', 'signature_fk', 'hash_check', 'state_check', 'reference_check', 'claim_check', 'cleanup_lease_check', 'upload_lease_check'] as $suffix) {
            $manifest["legal_signature_artifacts_{$suffix}"] = 'legal_signature_artifacts';
        }

        return $manifest;
    }
};
