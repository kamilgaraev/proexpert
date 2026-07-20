<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('legal_archive_document_type_profiles')) {
            Schema::table('legal_archive_document_type_profiles', static function (Blueprint $table): void {
                if (! Schema::hasColumn('legal_archive_document_type_profiles', 'allowed_signature_kinds')) {
                    $table->jsonb('allowed_signature_kinds')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_document_type_profiles', 'required_signature_kinds')) {
                    $table->jsonb('required_signature_kinds')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_document_type_profiles', 'allowed_signature_formats')) {
                    $table->jsonb('allowed_signature_formats')->nullable();
                }
            });
        }
        if (Schema::hasTable('legal_archive_file_cleanup_debts')) {
            Schema::table('legal_archive_file_cleanup_debts', static function (Blueprint $table): void {
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'storage_version_id')) {
                    $table->text('storage_version_id')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'storage_etag')) {
                    $table->string('storage_etag', 255)->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'content_hash')) {
                    $table->char('content_hash', 64)->nullable();
                }
            });
        }
        if (! Schema::hasTable('legal_signature_requests')) {
            Schema::create('legal_signature_requests', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('party_id')->nullable();
                $table->string('method', 32);
                $table->string('provider', 128)->nullable();
                $table->string('status', 32);
                $table->char('signed_content_hash', 64);
                $table->jsonb('signers');
                $table->char('signer_snapshot_hash', 64);
                $table->string('profile_code', 191);
                $table->unsignedBigInteger('profile_lock_version');
                $table->jsonb('allowed_signature_kinds');
                $table->jsonb('required_signature_kinds');
                $table->jsonb('allowed_signature_formats');
                $table->char('requirement_snapshot_hash', 64);
                $table->char('correlation_id', 64);
                $table->string('provider_request_id', 255)->nullable();
                $table->char('callback_replay_hash', 64)->nullable();
                $table->char('callback_payload_hash', 64)->nullable();
                $table->jsonb('session_metadata')->nullable();
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->unsignedBigInteger('requested_by_user_id');
                $table->timestampTz('requested_at');
                $table->timestampTz('expires_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('legal_document_signatures')) {
            Schema::create('legal_document_signatures', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('signature_request_id');
                $table->unsignedBigInteger('party_id')->nullable();
                $table->string('method', 32);
                $table->string('provider', 128)->nullable();
                $table->string('signer_name', 255)->nullable();
                $table->jsonb('signers');
                $table->char('signed_content_hash', 64);
                $table->text('signature_path')->nullable();
                $table->char('signature_content_hash', 64)->nullable();
                $table->text('storage_version_id')->nullable();
                $table->string('storage_etag', 255)->nullable();
                $table->string('detected_mime_type', 127)->nullable();
                $table->jsonb('certificate_metadata');
                $table->jsonb('provider_metadata');
                $table->text('storage_location')->nullable();
                $table->timestampTz('signed_at');
                $table->timestampTz('verified_at')->nullable();
                $table->string('verification_status', 32);
                $table->string('signature_kind', 32);
                $table->string('container_format', 32)->nullable();
                $table->char('signer_snapshot_hash', 64);
                $table->unsignedBigInteger('signer_user_id')->nullable();
                $table->unsignedBigInteger('signer_organization_id')->nullable();
                $table->string('party_role_snapshot', 64)->nullable();
                $table->char('certificate_fingerprint', 64)->nullable();
                $table->string('certificate_serial', 128)->nullable();
                $table->text('certificate_issuer')->nullable();
                $table->timestampTz('certificate_valid_from')->nullable();
                $table->timestampTz('certificate_valid_until')->nullable();
                $table->boolean('authority_confirmed');
                $table->string('time_source', 32);
                $table->string('diagnostic_code', 128);
                $table->string('signing_session_id', 191)->nullable();
                $table->char('client_ip_hash', 64)->nullable();
                $table->char('user_agent_hash', 64)->nullable();
                $table->text('revocation_reason')->nullable();
                $table->unsignedBigInteger('registered_by_user_id')->nullable();
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('legal_signature_provider_operations')) {
            Schema::create('legal_signature_provider_operations', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('signature_request_id')->unique();
                $table->string('provider', 128);
                $table->string('status', 32);
                $table->char('correlation_id', 64);
                $table->char('provider_idempotency_key', 64)->unique();
                $table->char('request_idempotency_key', 64);
                $table->unsignedInteger('generation');
                $table->char('lease_token_hash', 64)->nullable();
                $table->timestampTz('lease_expires_at')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->string('provider_request_id', 255)->nullable();
                $table->text('redirect_url')->nullable();
                $table->timestampTz('session_expires_at')->nullable();
                $table->jsonb('session_metadata')->nullable();
                $table->string('last_error_code', 128)->nullable();
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();
            });
        }
        if (! Schema::hasTable('legal_signature_verifications')) {
            Schema::create('legal_signature_verifications', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('signature_id');
                $table->string('provider', 128);
                $table->string('status', 32);
                $table->char('signed_content_hash', 64);
                $table->jsonb('certificate_metadata');
                $table->jsonb('provider_metadata');
                $table->text('revocation_reason')->nullable();
                $table->unsignedBigInteger('verified_by_user_id')->nullable();
                $table->timestampTz('verified_at');
                $table->string('idempotency_key', 191);
                $table->char('request_hash', 64);
                $table->timestampsTz();
            });
        }
        $this->assertSchemaManifest();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_signature_migrations_are_forward_only');
    }

    private function assertSchemaManifest(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        $manifest = [
            ['legal_signature_requests', 'signed_content_hash', 'bpchar', 'NO'],
            ['legal_signature_requests', 'signers', 'jsonb', 'NO'],
            ['legal_signature_requests', 'signer_snapshot_hash', 'bpchar', 'NO'],
            ['legal_signature_requests', 'required_signature_kinds', 'jsonb', 'NO'],
            ['legal_signature_requests', 'allowed_signature_formats', 'jsonb', 'NO'],
            ['legal_signature_requests', 'requirement_snapshot_hash', 'bpchar', 'NO'],
            ['legal_document_signatures', 'storage_version_id', 'text', 'YES'],
            ['legal_document_signatures', 'detected_mime_type', 'varchar', 'YES'],
            ['legal_document_signatures', 'signature_kind', 'varchar', 'NO'],
            ['legal_document_signatures', 'authority_confirmed', 'bool', 'NO'],
            ['legal_signature_provider_operations', 'id', 'uuid', 'NO'],
            ['legal_signature_provider_operations', 'status', 'varchar', 'NO'],
            ['legal_signature_provider_operations', 'request_idempotency_key', 'bpchar', 'NO'],
            ['legal_signature_provider_operations', 'generation', 'int4', 'NO'],
            ['legal_signature_provider_operations', 'session_metadata', 'jsonb', 'YES'],
            ['legal_signature_verifications', 'signed_content_hash', 'bpchar', 'NO'],
            ['legal_archive_document_type_profiles', 'allowed_signature_kinds', 'jsonb', 'YES'],
            ['legal_archive_document_type_profiles', 'required_signature_kinds', 'jsonb', 'YES'],
            ['legal_archive_document_type_profiles', 'allowed_signature_formats', 'jsonb', 'YES'],
            ['legal_archive_file_cleanup_debts', 'storage_version_id', 'text', 'YES'],
            ['legal_archive_file_cleanup_debts', 'storage_etag', 'varchar', 'YES'],
            ['legal_archive_file_cleanup_debts', 'content_hash', 'bpchar', 'YES'],
        ];
        foreach ($manifest as [$table, $column, $type, $nullable]) {
            $actual = DB::selectOne('SELECT udt_name, is_nullable FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?', [$table, $column]);
            if ($actual === null || $actual->udt_name !== $type || $actual->is_nullable !== $nullable) {
                throw new RuntimeException("legal_signature_schema_manifest_mismatch:{$table}.{$column}");
            }
        }
    }
};
