<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ContingencyLedgerService;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
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
            'change_management_single_approved_change',
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
    public function standard_change_writer_can_lock_the_real_aggregate_table(): void
    {
        self::assertSame(
            'change_management_change_requests',
            DB::scalar("SELECT to_regclass('change_management_change_requests')::text"),
        );

        DB::transaction(static function (): void {
            DB::table('change_management_change_requests')
                ->where('id', -1)
                ->lockForUpdate()
                ->first();
        });

        self::assertTrue(true);
    }

    #[Test]
    public function concurrent_standard_approvals_converge_to_one_version_and_consumption(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create();
        $now = now();
        $contractorId = DB::table('contractors')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'PG race contractor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractorId,
            'number' => 'PG-RACE-'.bin2hex(random_bytes(4)),
            'date' => $now->toDateString(),
            'total_amount' => '1000.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $allocationId = DB::table('contract_project_allocations')->insertGetId([
            'contract_id' => $contractId,
            'project_id' => $project->id,
            'allocation_type' => 'fixed',
            'allocated_amount' => '1000.00',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $change = ChangeRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'change_number' => 'PG-RACE-'.bin2hex(random_bytes(4)),
            'title' => 'Concurrent approval',
            'reason' => 'contract_test',
            'description' => 'Concurrent approval contract',
            'initiator_type' => 'internal',
            'status' => 'internal_review',
            'reporting_currency' => 'RUB',
            'reporting_contract_project_allocation_id' => $allocationId,
            'contingency_opening_minor' => 100_000,
            'contingency_allocation_minor' => 0,
            'contingency_release_minor' => 0,
        ]);
        $change->impact()->create([
            'organization_id' => $organization->id,
            'cost_delta' => '100.00',
            'requires_customer_approval' => false,
        ]);
        app(ContingencyLedgerService::class)->append(
            $change,
            ContingencyMovement::recorded(
                type: 'opening',
                amountMinor: 100_000,
                currency: 'RUB',
                projectId: (int) $project->id,
                allocationId: $allocationId,
                sourceType: 'change_request',
                sourceId: (string) $change->id,
                sourceVersion: 0,
                idempotencyKey: 'pg-race-opening-'.$change->id,
            ),
            $now,
        );

        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'finance-approval-race-'.bin2hex(random_bytes(6)),
        );
        $children = [];
        try {
            DB::beginTransaction();
            DB::table('change_management_change_requests')
                ->where('id', $change->id)
                ->lockForUpdate()
                ->first();
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($change, $user): array {
                    $result = app(ChangeManagementService::class)->approveChange(
                        ChangeRequest::query()->findOrFail($change->id),
                        (int) $user->id,
                        '100.00',
                        'same request',
                    );

                    return ['status' => (string) $result->status];
                });
                $harness->release($worker);
            }
            $observer = $harness->independentConnection('finance_approval_observer');
            foreach ([1, 2] as $worker) {
                $harness->waitForPostgresWait(
                    $observer,
                    $harness->waitForWorkerBackendPid($worker),
                );
            }
            DB::commit();
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame('approved', $harness->result(1)['status']);
            self::assertSame('approved', $harness->result(2)['status']);
            self::assertSame(1, DB::table('change_management_approvals')->where('change_request_id', $change->id)->count());
            self::assertSame(1, DB::table('change_request_versions')->where('change_request_id', $change->id)->count());
            self::assertSame(1, DB::table('contingency_ledger_entries')
                ->where('source_type', 'change_request')
                ->where('source_id', (string) $change->id)
                ->where('movement_type', 'consumption')
                ->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    #[Test]
    public function concurrent_standard_submissions_converge_to_one_version_and_allocation(): void
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create();
        $now = now();
        $contractorId = DB::table('contractors')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'PG submit race contractor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractorId,
            'number' => 'PG-SUBMIT-'.bin2hex(random_bytes(4)),
            'date' => $now->toDateString(),
            'total_amount' => '1000.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $allocationId = DB::table('contract_project_allocations')->insertGetId([
            'contract_id' => $contractId,
            'project_id' => $project->id,
            'allocation_type' => 'fixed',
            'allocated_amount' => '1000.00',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $change = ChangeRequest::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'created_by_user_id' => $user->id,
            'change_number' => 'PG-SUBMIT-'.bin2hex(random_bytes(4)),
            'title' => 'Concurrent submit',
            'reason' => 'contract_test',
            'description' => 'Concurrent submit contract',
            'initiator_type' => 'internal',
            'status' => 'draft',
            'reporting_currency' => 'RUB',
            'reporting_contract_project_allocation_id' => $allocationId,
            'contingency_opening_minor' => 100_000,
            'contingency_allocation_minor' => 10_000,
            'contingency_release_minor' => 0,
        ]);
        app(ContingencyLedgerService::class)->append(
            $change,
            ContingencyMovement::recorded(
                type: 'opening',
                amountMinor: 100_000,
                currency: 'RUB',
                projectId: (int) $project->id,
                allocationId: $allocationId,
                sourceType: 'change_request',
                sourceId: (string) $change->id,
                sourceVersion: 0,
                idempotencyKey: 'pg-submit-opening-'.$change->id,
            ),
            $now,
        );

        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'finance-submit-race-'.bin2hex(random_bytes(6)),
        );
        $children = [];
        try {
            DB::beginTransaction();
            DB::table('change_management_change_requests')
                ->where('id', $change->id)
                ->lockForUpdate()
                ->first();
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($change): array {
                    $result = app(ChangeManagementService::class)->submitChange(
                        ChangeRequest::query()->findOrFail($change->id),
                    );

                    return ['status' => (string) $result->status];
                });
                $harness->release($worker);
            }
            $observer = $harness->independentConnection('finance_submit_observer');
            foreach ([1, 2] as $worker) {
                $harness->waitForPostgresWait(
                    $observer,
                    $harness->waitForWorkerBackendPid($worker),
                );
            }
            DB::commit();
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame('submitted', $harness->result(1)['status']);
            self::assertSame('submitted', $harness->result(2)['status']);
            self::assertSame(1, DB::table('change_request_versions')->where('change_request_id', $change->id)->count());
            self::assertSame(1, DB::table('contingency_ledger_entries')
                ->where('source_type', 'change_request')
                ->where('source_id', (string) $change->id)
                ->where('movement_type', 'allocation')
                ->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $harness->terminateAndReap($children);
            $harness->cleanup();
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
