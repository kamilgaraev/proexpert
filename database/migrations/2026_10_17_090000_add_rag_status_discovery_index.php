<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'ai_rag_status_discovery_scope_idx';

    private const EXCLUDED_TYPES = [
        'budget_import_row',
        'budgeting_report_source_close_record',
        'epm_data_mart_aggregate',
        'epm_data_mart_recalculation_run',
        'epm_data_mart_snapshot',
        'wip_forecast_adjustment',
        'wip_forecast_assumption',
        'wip_forecast_audit_event',
        'wip_forecast_line',
        'wip_forecast_version',
        'management_pnl_record',
        'management_pnl_snapshot',
        'budgeting_portfolio_snapshot',
        'portfolio_liquidity_projection',
        'portfolio_liquidity_source_gap',
        'portfolio_liquidity_source_version',
        'project_portfolio_health_projection',
        'project_control_baseline_version',
        'project_control_row',
        'project_control_snapshot',
        'project_finance_row',
        'project_finance_snapshot',
    ];

    public function up(): void
    {
        $this->change(true);
    }

    public function down(): void
    {
        $this->change(false);
    }

    private function change(bool $create): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('rag_discovery_index_requires_no_transaction');
        }
        $schema = $connection->selectOne("SELECT namespace.nspname AS name FROM pg_class relation
            JOIN pg_namespace namespace ON namespace.oid=relation.relnamespace
            WHERE relation.oid=to_regclass('ai_rag_status_sources')")?->name;
        if (! is_string($schema) || $schema === '') {
            throw new RuntimeException('rag_discovery_table_missing');
        }
        $qualifiedSchema = '"'.str_replace('"', '""', $schema).'"';
        $table = $qualifiedSchema.'."ai_rag_status_sources"';
        $index = $qualifiedSchema.'."'.self::INDEX.'"';
        $definition = "(organization_id, source_type, entity_type, project_id) WHERE ((entity_type)::text <> ALL ('{"
            .implode(',', self::EXCLUDED_TYPES)."}'::text[]))";
        $state = $this->state($connection, $schema, $definition);
        $this->assertExpected($state);
        if (($create && (int) ($state->valid ?? 0) === 1) || (! $create && $state === null)) {
            return;
        }
        $settings = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout,
            current_setting('lock_timeout') AS lock_timeout");
        try {
            $connection->selectOne("SELECT set_config('statement_timeout', '180s', false), set_config('lock_timeout', '5s', false)");
            if ($state !== null) {
                $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
            }
            if ($create) {
                $connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS "'.self::INDEX.'" ON '.$table.' '.$definition);
                $state = $this->state($connection, $schema, $definition);
                $this->assertExpected($state);
                if ((int) ($state->valid ?? 0) !== 1) {
                    throw new RuntimeException('rag_discovery_index_not_ready');
                }
            }
        } finally {
            $connection->selectOne("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)",
                [$settings->statement_timeout, $settings->lock_timeout]);
        }
    }

    private function assertExpected(?object $state): void
    {
        if ($state !== null && (int) $state->owned !== 1) {
            throw new RuntimeException('rag_discovery_index_target_mismatch');
        }
        if ($state !== null && (int) $state->expected !== 1) {
            throw new RuntimeException('rag_discovery_index_definition_mismatch');
        }
    }

    private function state(Connection $connection, string $schema, string $definition): ?object
    {
        return $connection->selectOne("SELECT CASE WHEN relation.relkind='i'
                AND state.indrelid=to_regclass(format('%I.ai_rag_status_sources', CAST(? AS text))) THEN 1 ELSE 0 END AS owned,
            CASE WHEN state.indisvalid AND state.indisready AND state.indislive THEN 1 ELSE 0 END AS valid,
            CASE WHEN state.indexrelid IS NULL THEN 0
                WHEN pg_get_indexdef(relation.oid)=format('CREATE INDEX %I ON %I.ai_rag_status_sources USING btree %s',
                CAST(? AS text), CAST(? AS text), CAST(? AS text)) THEN 1 ELSE 0 END AS expected
            FROM pg_class relation JOIN pg_namespace namespace ON namespace.oid=relation.relnamespace
            LEFT JOIN pg_index state ON state.indexrelid=relation.oid
            WHERE namespace.nspname=? AND relation.relname=?",
            [$schema, self::INDEX, $schema, $definition, $schema, self::INDEX]);
    }
};
