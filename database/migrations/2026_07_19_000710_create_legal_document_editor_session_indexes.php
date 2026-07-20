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
                throw new RuntimeException("legal_document_editor_index_descriptor_mismatch:{$name}");
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
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_session_indexes_forward_only');
    }

    private function indexes(): array
    {
        return [
            'legal_editor_sessions_document_key_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_document_key_unique ON legal_document_editor_sessions USING btree (document_key)',
            'legal_editor_sessions_generation_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_generation_unique ON legal_document_editor_sessions USING btree (organization_id, document_id, source_version_id, generation)',
            'legal_editor_sessions_saved_version_unique' => 'CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_saved_version_unique ON legal_document_editor_sessions USING btree (saved_version_id) WHERE saved_version_id IS NOT NULL',
            'legal_editor_sessions_active_version_unique' => "CREATE UNIQUE INDEX CONCURRENTLY legal_editor_sessions_active_version_unique ON legal_document_editor_sessions USING btree (organization_id, document_id, source_version_id) WHERE status IN ('active','processing')",
            'legal_editor_sessions_expiry_idx' => "CREATE INDEX CONCURRENTLY legal_editor_sessions_expiry_idx ON legal_document_editor_sessions USING btree (expires_at, id) WHERE status IN ('active','processing')",
        ];
    }

    private function descriptor(string $name): ?object
    {
        return DB::selectOne(<<<'SQL'
SELECT pg_get_indexdef(ic.oid) definition, tc.relname table_name, am.amname access_method,
 i.indisunique::integer is_unique, i.indisprimary::integer is_primary,
 i.indimmediate::integer is_immediate, i.indisexclusion::integer is_exclusion,
 i.indnullsnotdistinct::integer nulls_not_distinct, i.indnkeyatts, i.indnatts,
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
            && $this->normalize((string) $descriptor->definition) === $this->normalize($sql);
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
