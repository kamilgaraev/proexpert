<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class FinanceOwnerPostgresContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_REPORT_FINANCE_PG_CONTRACT') !== '1') {
            self::markTestSkipped('Set RUN_REPORT_FINANCE_PG_CONTRACT=1 in isolated PostgreSQL CI.');
        }
        self::assertSame('pgsql', DB::connection()->getDriverName());
    }

    #[Test]
    public function reporting_owner_tables_have_real_append_only_triggers(): void
    {
        $expected = [
            'budgeting_project_finance_snapshots' => 'budgeting_project_finance_snapshots_append_only',
            'budgeting_project_finance_rows' => 'budgeting_project_finance_rows_append_only',
            'contract_settlement_source_facts' => 'contract_settlement_source_facts_append_only',
            'contract_settlement_owner_versions' => 'contract_settlement_owner_versions_append_only',
            'contract_settlement_owner_history_checkpoints' => 'contract_settlement_owner_checkpoints_append_only',
            'contract_settlement_exposure_snapshots' => 'contract_settlement_snapshots_append_only',
            'management_pnl_snapshots' => 'management_pnl_snapshots_append_only',
            'management_pnl_rows' => 'management_pnl_rows_append_only',
            'change_request_versions' => 'change_request_versions_append_only',
            'contingency_ledger_entries' => 'contingency_ledger_entries_append_only',
            'change_claim_snapshots' => 'change_claim_snapshots_append_only',
        ];

        foreach ($expected as $table => $trigger) {
            $row = DB::table('pg_trigger as trigger')
                ->join('pg_class as relation', 'relation.oid', '=', 'trigger.tgrelid')
                ->where('relation.relname', $table)
                ->where('trigger.tgname', $trigger)
                ->where('trigger.tgisinternal', false)
                ->selectRaw('pg_get_triggerdef(trigger.oid) as definition')
                ->first();
            self::assertNotNull($row, $table);
            self::assertStringContainsString('BEFORE UPDATE OR DELETE', (string) $row->definition);
        }
    }

    #[Test]
    public function first_writer_identity_indexes_are_present_and_unique(): void
    {
        foreach ([
            'budgeting_project_finance_snapshot_identity_unique',
            'contract_settlement_source_identity_unique',
            'management_pnl_snapshot_identity_unique',
            'change_request_version_identity_unique',
            'contingency_ledger_idempotency_unique',
            'change_claim_snapshot_identity_unique',
        ] as $index) {
            $row = DB::table('pg_indexes')
                ->where('schemaname', DB::raw('current_schema()'))
                ->where('indexname', $index)
                ->first();
            self::assertNotNull($row, $index);
            self::assertStringContainsString('UNIQUE INDEX', mb_strtoupper((string) $row->indexdef));
        }
    }

    #[Test]
    public function append_only_triggers_reject_real_mutations(): void
    {
        foreach (array_values([
            'reports_project_finance_append_only',
            'reports_contract_settlement_append_only',
            'reports_management_pnl_append_only',
            'reports_change_claim_append_only',
        ]) as $index => $function) {
            DB::beginTransaction();
            try {
                $table = 'report_append_only_probe_'.$index;
                DB::statement('CREATE TEMP TABLE '.$table.' (id integer primary key, value integer)');
                DB::statement('CREATE TRIGGER '.$table.'_guard BEFORE UPDATE OR DELETE ON '.$table
                    .' FOR EACH ROW EXECUTE FUNCTION '.$function.'()');
                DB::table($table)->insert(['id' => 1, 'value' => 1]);
                DB::table($table)->where('id', 1)->update(['value' => 2]);
                self::fail($function.' accepted UPDATE');
            } catch (\Illuminate\Database\QueryException $exception) {
                self::assertStringContainsString('append-only', $exception->getMessage(), $function);
            } finally {
                DB::rollBack();
            }
        }
    }
}
