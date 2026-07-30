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
}
