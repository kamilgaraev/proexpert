<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'ai_rag_expected_coverage_cover_idx' => 'CREATE INDEX ai_rag_expected_coverage_cover_idx ON public.ai_rag_expected_sources USING btree '
            .'(organization_id, generation, source_type, entity_type) INCLUDE (id, project_id, identity_project_id, identity_part_key, entity_id, checksum, pending_since, created_at, updated_at)',
        'ai_rag_expected_identity_unique' => 'CREATE UNIQUE INDEX ai_rag_expected_identity_unique ON public.ai_rag_expected_sources USING btree '
            .'(organization_id, generation, identity_project_id, source_type, entity_type, entity_id, identity_part_key)',
        'ai_rag_expected_type_entity_idx' => 'CREATE INDEX ai_rag_expected_type_entity_idx ON public.ai_rag_expected_sources USING btree '
            .'(organization_id, generation, source_type, entity_type, entity_id)',
        'ai_rag_expected_retention_idx' => 'CREATE INDEX ai_rag_expected_retention_idx ON public.ai_rag_expected_sources USING btree (organization_id, created_at, id)',
        'ai_rag_expected_sources_pkey' => 'CREATE UNIQUE INDEX ai_rag_expected_sources_pkey ON public.ai_rag_expected_sources USING btree (id)',
        'ai_rag_expected_scope_idx' => 'CREATE INDEX ai_rag_expected_scope_idx ON public.ai_rag_expected_sources USING btree (organization_id, generation, source_type, project_id)',
    ];

    public function up(): void
    {
        $connection = DB::connection();
        $settings = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        try {
            $connection->selectOne("SELECT set_config('statement_timeout', '180s', false), set_config('lock_timeout', '5s', false)");
            foreach (array_keys(self::INDEXES) as $name) {
                $index = $this->indexState($name);
                if ($index === null) {
                    continue;
                }
                if ((int) $index->healthy !== 1) {
                    throw new RuntimeException('rag_projection_index_unhealthy: public.'.$name);
                }
                if ((int) $index->can_reindex !== 1) {
                    Log::warning('rag_projection_index_maintenance_deferred', ['index' => 'public.'.$name, 'reason' => 'non_owner']);
                    continue;
                }
                try {
                    $connection->statement('REINDEX INDEX public.'.$name);
                } catch (QueryException $exception) {
                    if (($exception->errorInfo[0] ?? null) !== '42501') {
                        throw $exception;
                    }
                    if ((int) ($this->indexState($name)->healthy ?? 0) !== 1) {
                        throw new RuntimeException('rag_projection_index_unhealthy: public.'.$name, 0, $exception);
                    }
                    Log::warning('rag_projection_index_maintenance_deferred', [
                        'index' => 'public.'.$name, 'reason' => 'insufficient_privilege', 'sqlstate' => '42501',
                    ]);

                    continue;
                }
                if ((int) ($this->indexState($name)->healthy ?? 0) !== 1) {
                    throw new RuntimeException('rag_projection_index_unhealthy: public.'.$name);
                }
            }
        } finally {
            $connection->selectOne("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)",
                [$settings->statement_timeout, $settings->lock_timeout]);
        }
    }

    public function down(): void {}

    private function indexState(string $name): ?object
    {
        return DB::connection()->selectOne("SELECT
            CASE WHEN c.relkind = 'i' AND i.indrelid = to_regclass('public.ai_rag_expected_sources')
                AND i.indisvalid AND i.indisready AND i.indislive AND pg_get_indexdef(c.oid) = ? THEN 1 ELSE 0 END AS healthy,
            CASE WHEN r.rolsuper OR pg_has_role(current_user, c.relowner, 'USAGE')
                OR pg_has_role(current_user, t.relowner, 'USAGE') THEN 1 ELSE 0 END AS can_reindex
            FROM pg_class c
            LEFT JOIN pg_index i ON i.indexrelid = c.oid
            LEFT JOIN pg_class t ON t.oid = i.indrelid
            JOIN pg_roles r ON r.rolname = current_user
            WHERE c.oid = to_regclass(?)", [self::INDEXES[$name], 'public.'.$name]);
    }
};
