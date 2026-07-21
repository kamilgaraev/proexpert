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
        foreach ($this->indexes() as $name => $sql) {
            $descriptor = $this->descriptor($name);
            if ($descriptor !== null && ! $this->matches($descriptor, $sql)) {
                if ((bool) $descriptor->constraint_owned || (bool) $descriptor->is_primary) {
                    throw new RuntimeException("legal_document_editor_index_descriptor_mismatch:{$name}");
                }
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $descriptor = null;
            }
            if ($descriptor !== null && (! (bool) $descriptor->valid || ! (bool) $descriptor->ready || ! (bool) $descriptor->live)) {
                DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $descriptor = null;
            }
            if ($descriptor === null) {
                DB::statement($sql);
            }
            $verified = $this->descriptor($name);
            if ($verified === null || ! $this->matches($verified, $sql) || ! (bool) $verified->valid || ! (bool) $verified->ready || ! (bool) $verified->live) {
                throw new RuntimeException("legal_document_editor_index_descriptor_mismatch:{$name}");
            }
        }
        $this->assertIndexManifest();
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_session_indexes_forward_only');
    }

    public function assertIndexManifest(): void
    {
        foreach ($this->indexes() as $name => $sql) {
            $descriptor = $this->descriptor($name);
            if ($descriptor === null || ! (bool) $descriptor->valid || ! (bool) $descriptor->ready || ! (bool) $descriptor->live) {
                throw new RuntimeException("legal_document_editor_index_manifest_mismatch:{$name}");
            }
        }
        $expected = [
            'legal_document_editor_sessions' => ['legal_document_editor_sessions_pkey', 'legal_editor_sessions_document_key_unique',
                'legal_editor_sessions_generation_unique', 'legal_editor_sessions_tenant_unique', 'legal_editor_sessions_binding_unique',
                'legal_editor_sessions_saved_version_unique', 'legal_editor_sessions_active_version_unique', 'legal_editor_sessions_expiry_idx'],
            'legal_document_editor_participants' => ['legal_document_editor_participants_pkey',
                'legal_editor_participants_session_unique', 'legal_editor_participants_user_idx'],
            'legal_document_editor_saves' => ['legal_document_editor_saves_pkey', 'legal_editor_saves_generation_unique',
                'legal_editor_saves_replay_unique', 'legal_editor_saves_operation_unique',
                'legal_editor_saves_saved_version_unique', 'legal_editor_saves_supersedes_unique',
                'legal_editor_saves_lease_idx'],
        ];
        foreach ($expected as $table => $names) {
            $actual = array_map(static fn (object $row): string => (string) $row->name, DB::select(<<<'SQL'
SELECT i.relname name FROM pg_index x JOIN pg_class i ON i.oid=x.indexrelid
JOIN pg_class t ON t.oid=x.indrelid JOIN pg_namespace n ON n.oid=t.relnamespace
WHERE n.nspname=current_schema() AND t.relname=? ORDER BY i.relname
SQL, [$table]));
            sort($names);
            if ($actual !== $names) {
                throw new RuntimeException("legal_document_editor_index_set_mismatch:{$table}");
            }
        }
    }

    private function indexes(): array
    {
        return [
            'legal_archive_versions_editor_file_ownership_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_archive_versions_editor_file_ownership_unique ON legal_archive_document_versions USING btree (id, document_file_id, document_id, organization_id)',
            'legal_editor_sessions_document_key_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_document_key_unique ON legal_document_editor_sessions USING btree (document_key)',
            'legal_editor_sessions_generation_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_generation_unique ON legal_document_editor_sessions USING btree (organization_id, document_id, source_version_id, generation)',
            'legal_editor_sessions_tenant_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_tenant_unique ON legal_document_editor_sessions USING btree (id, organization_id)',
            'legal_editor_sessions_binding_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_binding_unique ON legal_document_editor_sessions USING btree (id, organization_id, document_id, source_version_id, document_file_id)',
            'legal_editor_sessions_saved_version_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_saved_version_unique ON legal_document_editor_sessions USING btree (saved_version_id) WHERE saved_version_id IS NOT NULL',
            'legal_editor_sessions_active_version_unique' => "CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_active_version_unique ON legal_document_editor_sessions USING btree (organization_id, document_id, source_version_id) WHERE status IN ('active','processing')",
            'legal_editor_sessions_expiry_idx' => "CREATE INDEX CONCURRENTLY legal_editor_sessions_expiry_idx ON legal_document_editor_sessions USING btree (expires_at, id) WHERE status IN ('active','processing')",
            'legal_editor_participants_session_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_participants_session_unique ON legal_document_editor_participants USING btree (editor_session_id)',
            'legal_editor_participants_user_idx' => 'CREATE INDEX CONCURRENTLY legal_editor_participants_user_idx ON legal_document_editor_participants USING btree (organization_id, user_id, joined_at) WHERE user_id IS NOT NULL',
            'legal_editor_saves_generation_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_saves_generation_unique ON legal_document_editor_saves USING btree (editor_session_id, save_generation)',
            'legal_editor_saves_replay_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_saves_replay_unique ON legal_document_editor_saves USING btree (editor_session_id, replay_hash)',
            'legal_editor_saves_supersedes_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_saves_supersedes_unique ON legal_document_editor_saves USING btree (supersedes_save_id) WHERE supersedes_save_id IS NOT NULL',
            'legal_editor_saves_operation_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_saves_operation_unique ON legal_document_editor_saves USING btree (operation_id) WHERE operation_id IS NOT NULL',
            'legal_editor_saves_saved_version_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_saves_saved_version_unique ON legal_document_editor_saves USING btree (saved_version_id) WHERE saved_version_id IS NOT NULL',
            'legal_editor_saves_lease_idx' => "CREATE INDEX CONCURRENTLY legal_editor_saves_lease_idx ON legal_document_editor_saves USING btree (lease_expires_at, id) WHERE state = 'processing'",
        ];
    }

    private function descriptor(string $name): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT pg_get_indexdef(ic.oid) definition, tc.relname table_name, am.amname access_method,
 i.indisunique::integer is_unique, i.indisprimary::integer is_primary,
 i.indimmediate::integer is_immediate, i.indisexclusion::integer is_exclusion,
 COALESCE((to_jsonb(i)->>'indnullsnotdistinct')::boolean,false)::integer nulls_not_distinct, i.indnkeyatts, i.indnatts,
 (i.indpred IS NOT NULL)::integer has_predicate,
 i.indisvalid::integer valid, i.indisready::integer ready, i.indislive::integer live,
 EXISTS(SELECT 1 FROM pg_constraint c WHERE c.conindid=ic.oid)::integer constraint_owned
FROM pg_class ic JOIN pg_namespace n ON n.oid=ic.relnamespace
JOIN pg_index i ON i.indexrelid=ic.oid JOIN pg_class tc ON tc.oid=i.indrelid
JOIN pg_am am ON am.oid=ic.relam WHERE n.nspname=current_schema() AND ic.relname=?
SQL, [$name]);
    }

    private function matches(object $descriptor, string $sql): bool
    {
        preg_match('/ ON ([a-z_]+) USING btree \(([^)]+)\)/i', $sql, $match);
        $keys = isset($match[2]) ? count(explode(',', $match[2])) : 0;

        return isset($match[1]) && $descriptor->table_name === $match[1] && $descriptor->access_method === 'btree'
            && (bool) $descriptor->is_unique === str_starts_with($sql, 'CREATE UNIQUE INDEX')
            && ! (bool) $descriptor->is_primary && (bool) $descriptor->is_immediate
            && ! (bool) $descriptor->is_exclusion && ! (bool) $descriptor->nulls_not_distinct
            && ! (bool) $descriptor->constraint_owned && (int) $descriptor->indnkeyatts === $keys
            && (int) $descriptor->indnatts === $keys
            && (bool) $descriptor->has_predicate === str_contains(strtolower($sql), ' where ');
    }

    private function normalize(string $sql): string
    {
        $schema = strtolower((string) DB::selectOne('SELECT current_schema() name')->name);
        $sql = strtolower(str_replace([' concurrently ', ';', '"'], [' ', '', ''], $sql));
        $sql = str_replace([$schema.'.'], '', $sql);
        $sql = (string) preg_replace('/::[a-z_ ]+(?:\[\])?/', '', $sql);

        return (string) preg_replace('/\s+/', ' ', trim($sql));
    }
};
