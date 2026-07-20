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
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'document_id')) {
                    $table->unsignedBigInteger('document_id')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'document_version_id')) {
                    $table->unsignedBigInteger('document_version_id')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'debt_key')) {
                    $table->char('debt_key', 64)->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'storage_etag')) {
                    $table->string('storage_etag', 255)->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'content_hash')) {
                    $table->char('content_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'lease_token_hash')) {
                    $table->char('lease_token_hash', 64)->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'lease_expires_at')) {
                    $table->timestampTz('lease_expires_at')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'last_attempt_at')) {
                    $table->timestampTz('last_attempt_at')->nullable();
                }
                if (! Schema::hasColumn('legal_archive_file_cleanup_debts', 'dead_lettered_at')) {
                    $table->timestampTz('dead_lettered_at')->nullable();
                }
            });
            $this->prepareCleanupDebtKeys();
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
                $table->char('requirement_group_key', 64);
                $table->unsignedBigInteger('replaces_request_id')->nullable();
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
                $table->unsignedBigInteger('signature_request_id');
                $table->string('provider', 128);
                $table->string('status', 32);
                $table->char('correlation_id', 64);
                $table->char('provider_idempotency_key', 64)->unique();
                $table->char('request_idempotency_key', 64);
                $table->unsignedInteger('generation');
                $table->uuid('supersedes_operation_id')->nullable();
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
            ['legal_signature_requests', 'requirement_group_key', 'bpchar', 'NO'],
            ['legal_signature_requests', 'replaces_request_id', 'int8', 'YES'],
            ['legal_signature_requests', 'requirement_snapshot_hash', 'bpchar', 'NO'],
            ['legal_document_signatures', 'storage_version_id', 'text', 'YES'],
            ['legal_document_signatures', 'detected_mime_type', 'varchar', 'YES'],
            ['legal_document_signatures', 'signature_kind', 'varchar', 'NO'],
            ['legal_document_signatures', 'authority_confirmed', 'bool', 'NO'],
            ['legal_signature_provider_operations', 'id', 'uuid', 'NO'],
            ['legal_signature_provider_operations', 'status', 'varchar', 'NO'],
            ['legal_signature_provider_operations', 'request_idempotency_key', 'bpchar', 'NO'],
            ['legal_signature_provider_operations', 'generation', 'int4', 'NO'],
            ['legal_signature_provider_operations', 'supersedes_operation_id', 'uuid', 'YES'],
            ['legal_signature_provider_operations', 'session_metadata', 'jsonb', 'YES'],
            ['legal_signature_verifications', 'signed_content_hash', 'bpchar', 'NO'],
            ['legal_archive_document_type_profiles', 'allowed_signature_kinds', 'jsonb', 'YES'],
            ['legal_archive_document_type_profiles', 'required_signature_kinds', 'jsonb', 'YES'],
            ['legal_archive_document_type_profiles', 'allowed_signature_formats', 'jsonb', 'YES'],
            ['legal_archive_file_cleanup_debts', 'storage_version_id', 'text', 'YES'],
            ['legal_archive_file_cleanup_debts', 'document_id', 'int8', 'YES'],
            ['legal_archive_file_cleanup_debts', 'document_version_id', 'int8', 'YES'],
            ['legal_archive_file_cleanup_debts', 'debt_key', 'bpchar', 'NO'],
            ['legal_archive_file_cleanup_debts', 'storage_etag', 'varchar', 'YES'],
            ['legal_archive_file_cleanup_debts', 'content_hash', 'bpchar', 'YES'],
            ['legal_archive_file_cleanup_debts', 'lease_token_hash', 'bpchar', 'YES'],
            ['legal_archive_file_cleanup_debts', 'lease_expires_at', 'timestamptz', 'YES'],
            ['legal_archive_file_cleanup_debts', 'last_attempt_at', 'timestamptz', 'YES'],
            ['legal_archive_file_cleanup_debts', 'dead_lettered_at', 'timestamptz', 'YES'],
        ];
        foreach ($manifest as [$table, $column, $type, $nullable]) {
            $actual = DB::selectOne('SELECT udt_name, is_nullable FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?', [$table, $column]);
            if ($actual === null || $actual->udt_name !== $type || $actual->is_nullable !== $nullable) {
                throw new RuntimeException("legal_signature_schema_manifest_mismatch:{$table}.{$column}");
            }
        }
        foreach ([
            'legal_signature_requests' => 32,
            'legal_document_signatures' => 45,
            'legal_signature_provider_operations' => 24,
            'legal_signature_verifications' => 17,
        ] as $table => $expectedColumns) {
            $descriptor = DB::selectOne('SELECT c.relkind, count(a.attname) FILTER (WHERE a.attnum > 0 AND NOT a.attisdropped) AS column_count FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attribute a ON a.attrelid=c.oid WHERE n.nspname=current_schema() AND c.relname=? GROUP BY c.relkind', [$table]);
            if ($descriptor === null || $descriptor->relkind !== 'r' || (int) $descriptor->column_count !== $expectedColumns) {
                throw new RuntimeException("legal_signature_table_descriptor_mismatch:{$table}");
            }
            $primary = DB::selectOne("SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname=? AND c.contype='p'", [$table]);
            if ($primary === null || $this->normalizeDescriptor((string) $primary->definition) !== 'primarykeyid') {
                throw new RuntimeException("legal_signature_primary_key_descriptor_mismatch:{$table}");
            }
        }
        foreach (['legal_signature_requests', 'legal_document_signatures', 'legal_signature_verifications'] as $table) {
            $sequence = DB::selectOne('SELECT pg_get_serial_sequence(?, ?) AS name', [DB::selectOne('SELECT quote_ident(current_schema())||\'.\'||quote_ident(?) AS name', [$table])->name, 'id']);
            if ($sequence === null || ! is_string($sequence->name) || $sequence->name === '') {
                throw new RuntimeException("legal_signature_sequence_descriptor_mismatch:{$table}");
            }
        }
        foreach ([
            ['legal_signature_requests', 'method', 32], ['legal_signature_requests', 'provider', 128],
            ['legal_signature_requests', 'status', 32], ['legal_signature_requests', 'profile_code', 191],
            ['legal_document_signatures', 'method', 32], ['legal_document_signatures', 'provider', 128],
            ['legal_document_signatures', 'verification_status', 32], ['legal_document_signatures', 'signature_kind', 32],
            ['legal_signature_provider_operations', 'status', 32], ['legal_signature_provider_operations', 'provider', 128],
            ['legal_signature_verifications', 'status', 32], ['legal_signature_verifications', 'provider', 128],
        ] as [$table, $column, $length]) {
            $actual = DB::selectOne('SELECT character_maximum_length AS length FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?', [$table, $column]);
            if ($actual === null || (int) $actual->length !== $length) {
                throw new RuntimeException("legal_signature_column_length_mismatch:{$table}.{$column}");
            }
        }
    }

    private function normalizeDescriptor(string $value): string
    {
        return (string) preg_replace('/["()\s]+/', '', strtolower($value));
    }

    private function prepareCleanupDebtKeys(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("UPDATE legal_archive_file_cleanup_debts SET debt_key = md5(organization_id::text || ':' || storage_path || ':' || COALESCE(storage_version_id, 'legacy')) || md5('most:' || organization_id::text || ':' || storage_path || ':' || COALESCE(storage_version_id, 'legacy')) WHERE debt_key IS NULL");
        DB::statement('ALTER TABLE legal_archive_file_cleanup_debts ALTER COLUMN debt_key SET NOT NULL');
        $legacy = DB::selectOne("SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_archive_file_cleanup_debts' AND c.conname='legal_archive_cleanup_debts_object_unique'");
        if ($legacy !== null) {
            if ($this->normalizeDescriptor((string) $legacy->definition) !== 'uniqueorganization_id,storage_path') {
                throw new RuntimeException('legal_signature_cleanup_legacy_unique_descriptor_mismatch');
            }
            DB::statement('ALTER TABLE legal_archive_file_cleanup_debts DROP CONSTRAINT legal_archive_cleanup_debts_object_unique');
        }
    }
};
