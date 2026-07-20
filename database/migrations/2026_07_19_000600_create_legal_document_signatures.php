<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

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
        if (! Schema::hasTable('legal_signature_artifacts')) {
            Schema::create('legal_signature_artifacts', static function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id');
                $table->unsignedBigInteger('signature_request_id');
                $table->char('artifact_key', 64);
                $table->text('storage_path');
                $table->text('storage_version_id')->nullable();
                $table->char('content_hash', 64);
                $table->string('state', 32);
                $table->unsignedInteger('claim_count')->default(0);
                $table->boolean('cleanup_owned')->default(false);
                $table->unsignedBigInteger('referenced_signature_id')->nullable();
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
            ['legal_signature_artifacts', 'artifact_key', 'bpchar', 'NO'],
            ['legal_signature_artifacts', 'storage_version_id', 'text', 'YES'],
            ['legal_signature_artifacts', 'content_hash', 'bpchar', 'NO'],
            ['legal_signature_artifacts', 'state', 'varchar', 'NO'],
            ['legal_signature_artifacts', 'claim_count', 'int4', 'NO'],
            ['legal_signature_artifacts', 'cleanup_owned', 'bool', 'NO'],
            ['legal_signature_artifacts', 'referenced_signature_id', 'int8', 'YES'],
        ];
        foreach ($manifest as [$table, $column, $type, $nullable]) {
            $actual = DB::selectOne('SELECT udt_name, is_nullable FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?', [$table, $column]);
            if ($actual === null || $actual->udt_name !== $type || $actual->is_nullable !== $nullable) {
                throw new RuntimeException("legal_signature_schema_manifest_mismatch:{$table}.{$column}");
            }
        }
        foreach ($this->orderedColumns() as $table => $expectedColumns) {
            $descriptor = DB::selectOne("SELECT c.relkind, string_agg(a.attname, ',' ORDER BY a.attnum) FILTER (WHERE a.attnum > 0 AND NOT a.attisdropped) AS columns FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace LEFT JOIN pg_attribute a ON a.attrelid=c.oid WHERE n.nspname=current_schema() AND c.relname=? GROUP BY c.relkind", [$table]);
            if ($descriptor === null || $descriptor->relkind !== 'r' || $descriptor->columns !== implode(',', $expectedColumns)) {
                throw new RuntimeException("legal_signature_table_descriptor_mismatch:{$table}");
            }
            $columnFlags = DB::selectOne(<<<'SQL'
SELECT count(*) FILTER (WHERE a.attidentity <> '') AS identity_count,
       count(*) FILTER (WHERE a.attgenerated <> '') AS generated_count,
       string_agg(a.attname, ',' ORDER BY a.attnum) FILTER (
           WHERE defaults.oid IS NOT NULL AND a.attname NOT IN ('id','attempt_count','claim_count','cleanup_owned')
       ) AS unexpected_defaults,
       max(pg_get_expr(defaults.adbin, defaults.adrelid)) FILTER (WHERE a.attname='attempt_count') AS attempt_default,
       max(pg_get_expr(defaults.adbin, defaults.adrelid)) FILTER (WHERE a.attname='claim_count') AS claim_default
       , max(pg_get_expr(defaults.adbin, defaults.adrelid)) FILTER (WHERE a.attname='cleanup_owned') AS cleanup_owned_default
FROM pg_attribute a
JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
LEFT JOIN pg_attrdef defaults ON defaults.adrelid=c.oid AND defaults.adnum=a.attnum
WHERE n.nspname=current_schema() AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped
SQL, [$table]);
            if ($columnFlags === null || (int) $columnFlags->identity_count !== 0
                || (int) $columnFlags->generated_count !== 0 || $columnFlags->unexpected_defaults !== null
                || ($table === 'legal_signature_provider_operations' && (string) $columnFlags->attempt_default !== '0')
                || ($table === 'legal_signature_artifacts' && (string) $columnFlags->claim_default !== '0')
                || ($table === 'legal_signature_artifacts' && (string) $columnFlags->cleanup_owned_default !== 'false')) {
                throw new RuntimeException("legal_signature_column_flags_mismatch:{$table}");
            }
            $primary = DB::selectOne("SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname=? AND c.contype='p'", [$table]);
            if ($primary === null || $this->normalizeDescriptor((string) $primary->definition) !== 'primarykeyid') {
                throw new RuntimeException("legal_signature_primary_key_descriptor_mismatch:{$table}");
            }
        }
        foreach (['legal_signature_requests', 'legal_document_signatures', 'legal_signature_verifications', 'legal_signature_artifacts'] as $table) {
            $sequence = DB::selectOne(<<<'SQL'
SELECT sequence_namespace.nspname AS sequence_schema, sequence_class.relname AS sequence_name,
       sequence_data.seqstart, sequence_data.seqincrement, sequence_data.seqmin, sequence_data.seqmax,
       sequence_data.seqcache, sequence_data.seqcycle::integer AS cycle, dependency.deptype,
       table_attribute.attname AS owned_column,
       pg_get_expr(column_default.adbin, column_default.adrelid) AS column_default
FROM pg_class table_class
JOIN pg_namespace table_namespace ON table_namespace.oid=table_class.relnamespace
JOIN pg_attribute table_attribute ON table_attribute.attrelid=table_class.oid AND table_attribute.attname='id'
JOIN pg_attrdef column_default ON column_default.adrelid=table_class.oid AND column_default.adnum=table_attribute.attnum
JOIN pg_depend dependency ON dependency.refobjid=table_class.oid AND dependency.refobjsubid=table_attribute.attnum AND dependency.deptype='a'
JOIN pg_class sequence_class ON sequence_class.oid=dependency.objid AND sequence_class.relkind='S'
JOIN pg_namespace sequence_namespace ON sequence_namespace.oid=sequence_class.relnamespace
JOIN pg_sequence sequence_data ON sequence_data.seqrelid=sequence_class.oid
WHERE table_namespace.nspname=current_schema() AND table_class.relname=?
SQL, [$table]);
            $expectedSequence = "{$table}_id_seq";
            if ($sequence === null || $sequence->sequence_schema !== DB::selectOne('SELECT current_schema() AS name')->name
                || $sequence->sequence_name !== $expectedSequence || $sequence->owned_column !== 'id'
                || $sequence->deptype !== 'a' || (int) $sequence->seqstart !== 1 || (int) $sequence->seqincrement !== 1
                || (int) $sequence->seqmin !== 1 || (int) $sequence->seqmax !== PHP_INT_MAX
                || (int) $sequence->seqcache !== 1 || (bool) $sequence->cycle
                || $this->normalizeDescriptor((string) $sequence->column_default) !== $this->normalizeDescriptor("nextval('{$expectedSequence}'::regclass)")) {
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
            ['legal_signature_artifacts', 'state', 32],
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

    private function orderedColumns(): array
    {
        return [
            'legal_signature_requests' => explode(',', 'id,organization_id,document_id,document_version_id,party_id,method,provider,status,signed_content_hash,signers,signer_snapshot_hash,profile_code,profile_lock_version,allowed_signature_kinds,required_signature_kinds,allowed_signature_formats,requirement_snapshot_hash,requirement_group_key,replaces_request_id,correlation_id,provider_request_id,callback_replay_hash,callback_payload_hash,session_metadata,idempotency_key,request_hash,requested_by_user_id,requested_at,expires_at,completed_at,created_at,updated_at'),
            'legal_document_signatures' => explode(',', 'id,organization_id,document_id,document_version_id,signature_request_id,party_id,method,provider,signer_name,signers,signed_content_hash,signature_path,signature_content_hash,storage_version_id,storage_etag,detected_mime_type,certificate_metadata,provider_metadata,storage_location,signed_at,verified_at,verification_status,signature_kind,container_format,signer_snapshot_hash,signer_user_id,signer_organization_id,party_role_snapshot,certificate_fingerprint,certificate_serial,certificate_issuer,certificate_valid_from,certificate_valid_until,authority_confirmed,time_source,diagnostic_code,signing_session_id,client_ip_hash,user_agent_hash,revocation_reason,registered_by_user_id,idempotency_key,request_hash,created_at,updated_at'),
            'legal_signature_provider_operations' => explode(',', 'id,organization_id,document_id,document_version_id,signature_request_id,provider,status,correlation_id,provider_idempotency_key,request_idempotency_key,generation,supersedes_operation_id,lease_token_hash,lease_expires_at,attempt_count,provider_request_id,redirect_url,session_expires_at,session_metadata,last_error_code,started_at,completed_at,created_at,updated_at'),
            'legal_signature_verifications' => explode(',', 'id,organization_id,document_id,document_version_id,signature_id,provider,status,signed_content_hash,certificate_metadata,provider_metadata,revocation_reason,verified_by_user_id,verified_at,idempotency_key,request_hash,created_at,updated_at'),
            'legal_signature_artifacts' => explode(',', 'id,organization_id,document_id,document_version_id,signature_request_id,artifact_key,storage_path,storage_version_id,content_hash,state,claim_count,cleanup_owned,referenced_signature_id,created_at,updated_at'),
        ];
    }

    private function prepareCleanupDebtKeys(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        $body = <<<'PLPGSQL'
BEGIN
    NEW.debt_key := encode(pg_catalog.sha256(pg_catalog.convert_to(
        NEW.organization_id::text || ':' || pg_catalog.octet_length(pg_catalog.convert_to(NEW.storage_path, 'UTF8'))::text || ':' || NEW.storage_path || ':' ||
        pg_catalog.octet_length(pg_catalog.convert_to(COALESCE(NEW.storage_version_id, 'legacy'), 'UTF8'))::text || ':' || COALESCE(NEW.storage_version_id, 'legacy'),
        'UTF8'
    )), 'hex');
    RETURN NEW;
END;
PLPGSQL;
        $function = DB::selectOne(<<<'SQL'
SELECT p.prosrc AS body, p.provolatile AS volatility, p.prosecdef::integer AS security_definer, p.proconfig
FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname=current_schema() AND p.proname='legal_signature_cleanup_debt_key_fill'
  AND pg_get_function_identity_arguments(p.oid)=''
SQL);
        if ($function === null) {
            DB::unprepared('CREATE FUNCTION legal_signature_cleanup_debt_key_fill() RETURNS trigger LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path TO pg_catalog AS $function$'.$body.'$function$;');
        } elseif ($this->normalizeBody((string) $function->body) !== $this->normalizeBody($body)
            || $function->volatility !== 'v' || (bool) $function->security_definer
            || ! str_contains(str_replace('"', '', implode(',', (array) $function->proconfig)), 'search_path=pg_catalog')) {
            throw new RuntimeException('legal_signature_cleanup_debt_key_function_descriptor_mismatch');
        }
        $trigger = DB::selectOne(<<<'SQL'
SELECT pg_get_triggerdef(t.oid, true) AS definition
FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname=current_schema() AND c.relname='legal_archive_file_cleanup_debts'
  AND t.tgname='legal_signature_cleanup_debt_key_fill' AND NOT t.tgisinternal
SQL);
        $triggerDefinition = 'CREATE TRIGGER legal_signature_cleanup_debt_key_fill BEFORE INSERT OR UPDATE OF organization_id, storage_path, storage_version_id ON legal_archive_file_cleanup_debts FOR EACH ROW EXECUTE FUNCTION legal_signature_cleanup_debt_key_fill()';
        if ($trigger === null) {
            DB::statement($triggerDefinition);
        } elseif ($this->normalizeDescriptor((string) $trigger->definition) !== $this->normalizeDescriptor($triggerDefinition)) {
            throw new RuntimeException('legal_signature_cleanup_debt_key_trigger_descriptor_mismatch');
        }
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS legal_archive_cleanup_debts_key_unique ON legal_archive_file_cleanup_debts (organization_id, debt_key)');
        do {
            $updated = DB::affectingStatement(<<<'SQL'
WITH batch AS (
    SELECT id FROM legal_archive_file_cleanup_debts
    WHERE debt_key IS NULL ORDER BY id LIMIT 500 FOR UPDATE SKIP LOCKED
)
UPDATE legal_archive_file_cleanup_debts debt
SET debt_key = encode(pg_catalog.sha256(pg_catalog.convert_to(
    debt.organization_id::text || ':' || pg_catalog.octet_length(pg_catalog.convert_to(debt.storage_path, 'UTF8'))::text || ':' || debt.storage_path || ':' ||
    pg_catalog.octet_length(pg_catalog.convert_to(COALESCE(debt.storage_version_id, 'legacy'), 'UTF8'))::text || ':' || COALESCE(debt.storage_version_id, 'legacy'),
    'UTF8'
)), 'hex')
FROM batch WHERE debt.id=batch.id
SQL);
        } while ($updated > 0);
        if ((int) DB::table('legal_archive_file_cleanup_debts')->whereNull('debt_key')->count() !== 0) {
            throw new RuntimeException('legal_signature_cleanup_debt_key_backfill_incomplete');
        }
        DB::statement('ALTER TABLE legal_archive_file_cleanup_debts ALTER COLUMN debt_key SET NOT NULL');
        $legacy = DB::selectOne("SELECT pg_get_constraintdef(c.oid, true) AS definition FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname=current_schema() AND t.relname='legal_archive_file_cleanup_debts' AND c.conname='legal_archive_cleanup_debts_object_unique'");
        if ($legacy !== null) {
            if ($this->normalizeDescriptor((string) $legacy->definition) !== 'uniqueorganization_id,storage_path') {
                throw new RuntimeException('legal_signature_cleanup_legacy_unique_descriptor_mismatch');
            }
        }
    }

    private function normalizeBody(string $value): string
    {
        return (string) preg_replace('/\s+/', '', strtolower(trim($value)));
    }
};
