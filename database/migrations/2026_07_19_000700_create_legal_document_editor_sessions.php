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
        if (! Schema::hasTable('legal_document_editor_sessions')) {
            Schema::create('legal_document_editor_sessions', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('source_version_id');
                $table->unsignedBigInteger('document_file_id');
                $table->unsignedBigInteger('opened_by_user_id');
                $table->string('provider', 40);
                $table->string('mode', 16);
                $table->string('status', 24);
                $table->unsignedInteger('generation');
                $table->unsignedInteger('next_save_generation')->default(1);
                $table->unsignedInteger('last_applied_generation')->default(0);
                $table->unsignedInteger('final_generation')->nullable();
                $table->string('document_key', 191);
                $table->char('source_content_hash', 64);
                $table->unsignedBigInteger('saved_version_id')->nullable();
                $table->timestampTz('expires_at');
                $table->timestampTz('completed_at')->nullable();
                $table->string('failure_code', 120)->nullable();
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
            });
        }
        if (! Schema::hasTable('legal_document_editor_participants')) {
            Schema::create('legal_document_editor_participants', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id');
                $table->uuid('editor_session_id');
                $table->char('actor_key', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('provider_user_id', 191)->nullable();
                $table->string('required_ability', 16);
                $table->timestampTz('joined_at');
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
            });
        }
        if (! Schema::hasTable('legal_document_editor_saves')) {
            Schema::create('legal_document_editor_saves', static function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->uuid('editor_session_id');
                $table->unsignedBigInteger('source_version_id');
                $table->unsignedBigInteger('document_file_id');
                $table->unsignedInteger('save_generation');
                $table->unsignedSmallInteger('callback_status');
                $table->char('replay_hash', 64);
                $table->uuid('operation_id')->nullable();
                $table->string('state', 16);
                $table->char('lease_owner_hash', 64)->nullable();
                $table->timestampTz('lease_expires_at')->nullable();
                $table->unsignedBigInteger('saved_version_id')->nullable();
                $table->char('content_hash', 64)->nullable();
                $table->boolean('terminal')->default(false);
                $table->timestampTz('completed_at')->nullable();
                $table->timestampTz('failed_at')->nullable();
                $table->timestampTz('created_at');
                $table->timestampTz('updated_at');
            });
        }
        $this->verifySchemaManifest();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_sessions_forward_only');
    }

    public function verifySchemaManifest(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }
        $tables = [
            'legal_document_editor_sessions' => [
                ['id', 'uuid', null, 'NO', null], ['organization_id', 'int8', null, 'NO', null],
                ['document_id', 'int8', null, 'NO', null], ['source_version_id', 'int8', null, 'NO', null],
                ['document_file_id', 'int8', null, 'NO', null], ['opened_by_user_id', 'int8', null, 'NO', null],
                ['provider', 'varchar', 40, 'NO', null], ['mode', 'varchar', 16, 'NO', null],
                ['status', 'varchar', 24, 'NO', null], ['generation', 'int4', null, 'NO', null],
                ['next_save_generation', 'int4', null, 'NO', '1'], ['last_applied_generation', 'int4', null, 'NO', '0'],
                ['final_generation', 'int4', null, 'YES', null], ['document_key', 'varchar', 191, 'NO', null],
                ['source_content_hash', 'bpchar', 64, 'NO', null], ['saved_version_id', 'int8', null, 'YES', null],
                ['expires_at', 'timestamptz', null, 'NO', null], ['completed_at', 'timestamptz', null, 'YES', null],
                ['failure_code', 'varchar', 120, 'YES', null], ['created_at', 'timestamptz', null, 'NO', null],
                ['updated_at', 'timestamptz', null, 'NO', null],
            ],
            'legal_document_editor_participants' => [
                ['id', 'uuid', null, 'NO', null], ['organization_id', 'int8', null, 'NO', null],
                ['editor_session_id', 'uuid', null, 'NO', null], ['actor_key', 'bpchar', 64, 'NO', null],
                ['user_id', 'int8', null, 'YES', null], ['provider_user_id', 'varchar', 191, 'YES', null],
                ['required_ability', 'varchar', 16, 'NO', null],
                ['joined_at', 'timestamptz', null, 'NO', null], ['created_at', 'timestamptz', null, 'NO', null],
                ['updated_at', 'timestamptz', null, 'NO', null],
            ],
            'legal_document_editor_saves' => [
                ['id', 'uuid', null, 'NO', null], ['organization_id', 'int8', null, 'NO', null],
                ['document_id', 'int8', null, 'NO', null], ['editor_session_id', 'uuid', null, 'NO', null],
                ['source_version_id', 'int8', null, 'NO', null], ['document_file_id', 'int8', null, 'NO', null],
                ['save_generation', 'int4', null, 'NO', null], ['callback_status', 'int2', null, 'NO', null],
                ['replay_hash', 'bpchar', 64, 'NO', null], ['operation_id', 'uuid', null, 'YES', null],
                ['state', 'varchar', 16, 'NO', null], ['lease_owner_hash', 'bpchar', 64, 'YES', null],
                ['lease_expires_at', 'timestamptz', null, 'YES', null], ['saved_version_id', 'int8', null, 'YES', null],
                ['content_hash', 'bpchar', 64, 'YES', null], ['terminal', 'bool', null, 'NO', 'false'],
                ['completed_at', 'timestamptz', null, 'YES', null], ['failed_at', 'timestamptz', null, 'YES', null],
                ['created_at', 'timestamptz', null, 'NO', null], ['updated_at', 'timestamptz', null, 'NO', null],
            ],
        ];
        foreach ($tables as $table => $expectedColumns) {
            $relation = DB::selectOne(<<<'SQL'
SELECT c.relkind,c.relpersistence,c.relrowsecurity::integer row_security,c.relforcerowsecurity::integer force_row_security,
       c.relispartition::integer is_partition,pg_get_userbyid(c.relowner) owner_name,current_user expected_owner,
       string_agg(a.attname, ',' ORDER BY a.attnum) FILTER (WHERE a.attnum>0 AND NOT a.attisdropped) columns,
       count(*) FILTER (WHERE a.attidentity<>'') identity_count,
       count(*) FILTER (WHERE a.attgenerated<>'') generated_count
FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
LEFT JOIN pg_attribute a ON a.attrelid=c.oid
WHERE n.nspname=current_schema() AND c.relname=? GROUP BY c.oid
SQL, [$table]);
            if ($relation === null || $relation->relkind !== 'r' || $relation->relpersistence !== 'p'
                || (bool) $relation->row_security || (bool) $relation->force_row_security || (bool) $relation->is_partition
                || $relation->owner_name !== $relation->expected_owner
                || $relation->columns !== implode(',', array_column($expectedColumns, 0))
                || (int) $relation->identity_count !== 0 || (int) $relation->generated_count !== 0) {
                throw new RuntimeException("legal_document_editor_relation_manifest_mismatch:{$table}");
            }
            $actualColumns = DB::select(<<<'SQL'
SELECT a.attname name,t.typname type,information_schema._pg_char_max_length(a.atttypid,a.atttypmod) max_length,
       information_schema._pg_datetime_precision(a.atttypid,a.atttypmod) datetime_precision,
       CASE WHEN a.attnotnull THEN 'NO' ELSE 'YES' END nullable, pg_get_expr(d.adbin,d.adrelid) default_value
FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace
JOIN pg_type t ON t.oid=a.atttypid LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
WHERE n.nspname=current_schema() AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum
SQL, [$table]);
            foreach ($expectedColumns as $offset => [$name, $type, $length, $nullable, $default]) {
                $actual = $actualColumns[$offset] ?? null;
                $actualDefault = $actual === null || $actual->default_value === null
                    ? null
                    : strtolower(str_replace(['::boolean', '::integer'], '', (string) $actual->default_value));
                if ($actual === null || $actual->name !== $name || $actual->type !== $type
                    || ($length === null ? $actual->max_length !== null : (int) $actual->max_length !== $length)
                    || ($type === 'timestamptz' && (int) $actual->datetime_precision !== 0)
                    || ($type !== 'timestamptz' && $actual->datetime_precision !== null)
                    || $actual->nullable !== $nullable || $actualDefault !== $default) {
                    throw new RuntimeException("legal_document_editor_column_manifest_mismatch:{$table}.{$name}");
                }
            }
            $primary = DB::selectOne(<<<'SQL'
SELECT c.conname,c.contype,c.condeferrable::integer deferrable,c.condeferred::integer deferred,
       c.convalidated::integer validated,pg_get_constraintdef(c.oid,true) definition,
       i.indisprimary::integer is_primary,i.indisunique::integer is_unique,i.indisvalid::integer index_valid,
       i.indisready::integer index_ready,i.indislive::integer index_live,i.indnkeyatts,i.indnatts,
       COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean,false)::integer nulls_not_distinct,am.amname access_method
FROM pg_constraint c JOIN pg_class t ON t.oid=c.conrelid JOIN pg_namespace n ON n.oid=t.relnamespace
JOIN pg_index i ON i.indexrelid=c.conindid JOIN pg_class ic ON ic.oid=i.indexrelid JOIN pg_am am ON am.oid=ic.relam
WHERE n.nspname=current_schema() AND t.relname=? AND c.contype='p'
SQL, [$table]);
            if ($primary === null || $primary->conname !== $table.'_pkey'
                || $this->normalize((string) $primary->definition) !== 'primarykey(id)'
                || (bool) $primary->deferrable || (bool) $primary->deferred || ! (bool) $primary->validated
                || ! (bool) $primary->is_primary || ! (bool) $primary->is_unique
                || ! (bool) $primary->index_valid || ! (bool) $primary->index_ready || ! (bool) $primary->index_live
                || (int) $primary->indnkeyatts !== 1 || (int) $primary->indnatts !== 1
                || (bool) $primary->nulls_not_distinct || $primary->access_method !== 'btree') {
                throw new RuntimeException("legal_document_editor_primary_key_manifest_mismatch:{$table}");
            }
            $sequences = DB::selectOne(<<<'SQL'
SELECT count(*) amount FROM pg_depend d JOIN pg_class s ON s.oid=d.objid
JOIN pg_class t ON t.oid=d.refobjid JOIN pg_namespace n ON n.oid=t.relnamespace
WHERE n.nspname=current_schema() AND t.relname=? AND s.relkind='S' AND d.deptype IN ('a','i')
SQL, [$table]);
            if ($sequences === null || (int) $sequences->amount !== 0) {
                throw new RuntimeException("legal_document_editor_sequence_manifest_mismatch:{$table}");
            }
        }
    }

    private function normalize(string $value): string
    {
        return (string) preg_replace('/["\s]+/', '', strtolower($value));
    }
};
