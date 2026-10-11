<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLES = [
        'public.ai_rag_expected_sources',
        'public.ai_assistant_status_snapshot_changes',
        'public.ai_assistant_status_snapshot_scoped_changes',
    ];

    public function up(): void
    {
        $connection = DB::connection();
        $settings = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout, current_setting('server_version_num') AS version");
        try {
            $connection->selectOne("SELECT set_config('statement_timeout', '180s', false), set_config('lock_timeout', '5s', false)");
            foreach (self::TABLES as $table) {
                $before = $this->indexState($connection, $table);
                $this->ensureMaintenancePrivilege($connection, $table, (int) $settings->version >= 170000);
                $connection->statement('REINDEX TABLE '.$table);
                $after = $this->indexState($connection, $table);
                if (array_column($before, 'definition', 'name') !== array_column($after, 'definition', 'name')) {
                    throw new RuntimeException('rag_storage_compaction_index_definition_changed: '.$table);
                }
                Log::info('rag_storage_index_compacted', [
                    'table' => $table,
                    'before_bytes' => array_sum(array_column($before, 'bytes')),
                    'after_bytes' => array_sum(array_column($after, 'bytes')),
                ]);
            }
        } finally {
            $connection->selectOne("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)",
                [$settings->statement_timeout, $settings->lock_timeout]);
        }
    }

    public function down(): void {}

    private function ensureMaintenancePrivilege(Connection $connection, string $table, bool $supportsMaintain): void
    {
        if (! $supportsMaintain) {
            $privilege = $connection->selectOne("SELECT CASE WHEN pg_has_role(current_user, c.relowner, 'USAGE') THEN 1 ELSE 0 END AS allowed FROM pg_class c WHERE c.oid = ?::regclass", [$table]);
            if ((int) ($privilege->allowed ?? 0) !== 1) {
                throw new RuntimeException('rag_storage_compaction_requires_owner: '.$table);
            }

            return;
        }

        $query = "SELECT CASE WHEN has_table_privilege(current_user, ?::regclass, 'MAINTAIN') THEN 1 ELSE 0 END AS allowed, CASE WHEN has_table_privilege(current_user, ?::regclass, 'MAINTAIN WITH GRANT OPTION') THEN 1 ELSE 0 END AS can_grant";
        $privilege = $connection->selectOne($query, [$table, $table]);
        if ((int) ($privilege->allowed ?? 0) === 1) {
            return;
        }
        if ((int) ($privilege->can_grant ?? 0) !== 1) {
            throw new RuntimeException('rag_storage_compaction_requires_maintain: '.$table);
        }
        $connection->statement('GRANT MAINTAIN ON TABLE '.$table.' TO CURRENT_USER');
        $privilege = $connection->selectOne($query, [$table, $table]);
        if ((int) ($privilege->allowed ?? 0) !== 1) {
            throw new RuntimeException('rag_storage_compaction_requires_maintain: '.$table);
        }
    }

    private function indexState(Connection $connection, string $table): array
    {
        $rows = $connection->select("SELECT c.relname AS name, pg_get_indexdef(c.oid) AS definition, pg_relation_size(c.oid) AS bytes, CASE WHEN i.indisvalid AND i.indisready AND i.indislive THEN 1 ELSE 0 END AS healthy FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE i.indrelid = ?::regclass ORDER BY c.relname", [$table]);
        foreach ($rows as $row) {
            if ((int) $row->healthy !== 1) {
                throw new RuntimeException('rag_storage_compaction_index_unhealthy: '.$table.'.'.$row->name);
            }
        }
        if ($rows === []) {
            throw new RuntimeException('rag_storage_compaction_indexes_missing: '.$table);
        }

        return array_map(static fn (object $row): array => ['name' => $row->name, 'definition' => $row->definition, 'bytes' => (int) $row->bytes], $rows);
    }
};
