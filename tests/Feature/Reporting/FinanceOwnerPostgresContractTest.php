<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerHistoryBackfillService;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerTimestamp;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerVersionRecorder;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerHistoryCheckpoint;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerVersion;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ContingencyLedgerService;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
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
    public function contract_settlement_checkpoints_match_exact_owner_versions(): void
    {
        $mismatchCount = DB::scalar(<<<'SQL'
SELECT COUNT(*)
FROM (
    SELECT
        checkpoint.organization_id,
        (
            (checkpoint.owner_counts->>'contract')::bigint
            + (checkpoint.owner_counts->>'contract_allocation')::bigint
            + (checkpoint.owner_counts->>'contract_performance_act')::bigint
            + (checkpoint.owner_counts->>'payment_document')::bigint
            + (checkpoint.owner_counts->>'payment_transaction')::bigint
        ) AS expected_count,
        COUNT(version.id) AS captured_count
    FROM contract_settlement_owner_history_checkpoints AS checkpoint
    LEFT JOIN contract_settlement_owner_versions AS version
        ON version.organization_id = checkpoint.organization_id
       AND version.occurred_at = checkpoint.completed_at
    GROUP BY checkpoint.id
) AS coverage
WHERE coverage.expected_count <> coverage.captured_count
SQL);

        self::assertSame(0, (int) $mismatchCount);
        self::assertSame(6, (int) DB::scalar(<<<'SQL'
SELECT datetime_precision
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'contract_settlement_owner_versions'
  AND column_name = 'occurred_at'
SQL));
        self::assertSame(6, (int) DB::scalar(<<<'SQL'
SELECT datetime_precision
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name = 'contract_settlement_owner_history_checkpoints'
  AND column_name = 'completed_at'
SQL));
    }

    #[Test]
    public function future_organization_checkpoint_and_owner_version_keep_exact_microseconds(): void
    {
        $organization = Organization::factory()->create();
        $checkpoint = ContractSettlementOwnerHistoryCheckpoint::query()
            ->where('organization_id', $organization->id)
            ->sole();
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $now = now();
        $contractorId = DB::table('contractors')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'PG checkpoint contractor',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contractId = DB::table('contracts')->insertGetId([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractorId,
            'number' => 'PG-CHECKPOINT-'.bin2hex(random_bytes(4)),
            'date' => $now->toDateString(),
            'total_amount' => '1000.00',
            'currency' => 'RUB',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $contract = Contract::query()->findOrFail($contractId);
        $exactAt = new DateTimeImmutable(
            $checkpoint->completed_at->addSecond()->format('Y-m-d\TH:i:s').'.123456+00:00',
        );
        $version = app(ContractSettlementOwnerVersionRecorder::class)->record(
            $contract,
            'upsert',
            $exactAt,
        );

        self::assertSame(
            $exactAt->format('Y-m-d H:i:s.u'),
            DB::scalar(
                "SELECT to_char(occurred_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') "
                .'FROM contract_settlement_owner_versions WHERE id = ?',
                [$version->id],
            ),
        );
        $before = new DateTimeImmutable(
            $exactAt->format('Y-m-d\TH:i:s').'.123455+00:00',
        );
        self::assertSame(0, DB::table('contract_settlement_owner_versions')
            ->where('organization_id', $organization->id)
            ->where('owner_type', 'contract')
            ->where('owner_id', (string) $contractId)
            ->where('occurred_at', '<=', ContractSettlementOwnerTimestamp::database($before))
            ->count());
        self::assertSame(1, DB::table('contract_settlement_owner_versions')
            ->where('organization_id', $organization->id)
            ->where('owner_type', 'contract')
            ->where('owner_id', (string) $contractId)
            ->where('occurred_at', '<=', ContractSettlementOwnerTimestamp::database($exactAt))
            ->count());

        $versionCount = DB::table('contract_settlement_owner_versions')
            ->where('organization_id', $organization->id)
            ->count();
        $replayed = app(ContractSettlementOwnerHistoryBackfillService::class)->backfill((int) $organization->id);
        self::assertSame($checkpoint->id, $replayed->id);
        self::assertSame($versionCount, DB::table('contract_settlement_owner_versions')
            ->where('organization_id', $organization->id)
            ->count());
    }

    #[Test]
    public function owner_history_foundation_migration_backfills_non_empty_sources_exactly_once(): void
    {
        $schema = 'report_r12_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA '.$schema);

        try {
            DB::statement('SET search_path TO '.$schema);
            $this->createContractSettlementFoundationSchema();
            $ownerTimestamp = '2026-08-05 12:00:00.000000+00:00';
            DB::table('organizations')->insert([
                'id' => 1,
                'created_at' => $ownerTimestamp,
                'updated_at' => $ownerTimestamp,
            ]);
            DB::table('contracts')->insert([
                'id' => 11,
                'organization_id' => 1,
                'is_onboarding_demo' => false,
                'created_at' => $ownerTimestamp,
                'updated_at' => $ownerTimestamp,
            ]);
            DB::table('contract_project_allocations')->insert([
                'id' => 21,
                'contract_id' => 11,
                'created_at' => $ownerTimestamp,
                'updated_at' => $ownerTimestamp,
            ]);
            DB::table('contract_performance_acts')->insert([
                'id' => 31,
                'contract_id' => 11,
                'created_at' => $ownerTimestamp,
                'updated_at' => $ownerTimestamp,
            ]);
            DB::table('payment_documents')->insert([
                'id' => 41,
                'organization_id' => 1,
                'invoiceable_type' => null,
                'invoiceable_id' => null,
                'created_at' => $ownerTimestamp,
                'updated_at' => $ownerTimestamp,
            ]);
            DB::table('payment_transactions')->insert([
                'id' => 51,
                'payment_document_id' => 41,
                'organization_id' => 1,
                'created_at' => $ownerTimestamp,
                'updated_at' => $ownerTimestamp,
            ]);

            $migration = require base_path(
                'database/migrations/2026_08_05_040000_seed_contract_settlement_owner_history_foundation.php',
            );
            $migration->up();

            $checkpoint = ContractSettlementOwnerHistoryCheckpoint::query()->sole();
            $types = [
                'contract',
                'contract_allocation',
                'contract_performance_act',
                'payment_document',
                'payment_transaction',
            ];
            foreach ($types as $type) {
                self::assertSame(1, $checkpoint->owner_counts[$type] ?? null, $type);
            }
            $checkpointTimestamp = ContractSettlementOwnerTimestamp::canonical($checkpoint->completed_at);
            $identities = [];
            foreach ($types as $type) {
                $versions = ContractSettlementOwnerVersion::query()
                    ->where('organization_id', 1)
                    ->where('owner_type', $type)
                    ->orderBy('owner_id')
                    ->get();
                self::assertCount(1, $versions, $type);
                foreach ($versions as $version) {
                    self::assertSame($checkpointTimestamp, ContractSettlementOwnerTimestamp::canonical(
                        $version->occurred_at,
                    ));
                    $identity = [
                        'organization_id' => 1,
                        'owner_type' => $type,
                        'owner_id' => (string) $version->owner_id,
                        'version' => (int) $version->version,
                        'operation' => 'upsert',
                        'occurred_at' => $checkpointTimestamp,
                        'payload' => $version->payload,
                    ];
                    self::assertSame(
                        hash('sha256', CanonicalJson::encode($identity)),
                        $version->owner_hash,
                        $type,
                    );
                    $identities[] = [
                        'type' => $type,
                        'id' => (string) $version->owner_id,
                        'version' => (int) $version->version,
                        'hash' => (string) $version->owner_hash,
                    ];
                }
            }
            self::assertSame(5, ContractSettlementOwnerVersion::query()->count());
            self::assertSame(hash('sha256', CanonicalJson::encode([
                'organization_id' => 1,
                'completed_at' => $checkpointTimestamp,
                'owners' => $identities,
            ])), $checkpoint->source_hash);
            self::assertSame(0, ContractSettlementOwnerVersion::query()
                ->where('occurred_at', '<=', ContractSettlementOwnerTimestamp::database(
                    $checkpoint->completed_at->subMicrosecond(),
                ))
                ->count());
            self::assertSame(5, ContractSettlementOwnerVersion::query()
                ->where('occurred_at', '<=', ContractSettlementOwnerTimestamp::database(
                    $checkpoint->completed_at,
                ))
                ->count());

            try {
                $migration->up();
                self::fail('The foundation migration accepted a second checkpoint.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('checkpoint already exists', $exception->getMessage());
            }
            self::assertSame(1, ContractSettlementOwnerHistoryCheckpoint::query()->count());
            self::assertSame(5, ContractSettlementOwnerVersion::query()->count());
            self::assertSame(1, (int) DB::scalar(<<<'SQL'
SELECT COUNT(*)
FROM pg_trigger AS trigger
INNER JOIN pg_class AS relation ON relation.oid = trigger.tgrelid
INNER JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
WHERE relation.relname = 'organizations'
  AND namespace.nspname = current_schema()
  AND trigger.tgname = 'most_seed_contract_settlement_owner_checkpoint_v1'
  AND NOT trigger.tgisinternal
SQL));
        } finally {
            DB::statement('RESET search_path');
            DB::statement('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
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

    private function createContractSettlementFoundationSchema(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE organizations (
    id bigint PRIMARY KEY,
    created_at timestamptz(0),
    updated_at timestamptz(0)
);
CREATE TABLE contracts (
    id bigint PRIMARY KEY,
    organization_id bigint NOT NULL,
    is_onboarding_demo boolean NOT NULL DEFAULT false,
    deleted_at timestamptz(0),
    created_at timestamptz(0),
    updated_at timestamptz(0)
);
CREATE TABLE contract_project_allocations (
    id bigint PRIMARY KEY,
    contract_id bigint NOT NULL,
    deleted_at timestamptz(0),
    created_at timestamptz(0),
    updated_at timestamptz(0)
);
CREATE TABLE contract_performance_acts (
    id bigint PRIMARY KEY,
    contract_id bigint NOT NULL,
    created_at timestamptz(0),
    updated_at timestamptz(0)
);
CREATE TABLE payment_documents (
    id bigint PRIMARY KEY,
    organization_id bigint NOT NULL,
    invoiceable_type text,
    invoiceable_id bigint,
    deleted_at timestamptz(0),
    created_at timestamptz(0),
    updated_at timestamptz(0)
);
CREATE TABLE payment_transactions (
    id bigint PRIMARY KEY,
    payment_document_id bigint NOT NULL,
    organization_id bigint NOT NULL,
    created_at timestamptz(0),
    updated_at timestamptz(0)
);
CREATE TABLE contract_settlement_owner_versions (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL,
    owner_type varchar(48) NOT NULL,
    owner_id varchar(96) NOT NULL,
    version integer NOT NULL,
    operation varchar(16) NOT NULL,
    occurred_at timestamptz(0) NOT NULL,
    payload jsonb NOT NULL,
    owner_hash char(64) NOT NULL,
    created_at timestamptz(0),
    updated_at timestamptz(0),
    CONSTRAINT contract_settlement_owner_version_unique
        UNIQUE (organization_id, owner_type, owner_id, version)
);
CREATE TABLE contract_settlement_owner_history_checkpoints (
    id bigserial PRIMARY KEY,
    organization_id bigint NOT NULL,
    completed_at timestamptz(0) NOT NULL,
    owner_counts jsonb NOT NULL,
    source_hash char(64) NOT NULL,
    created_at timestamptz(0),
    updated_at timestamptz(0),
    CONSTRAINT contract_settlement_owner_checkpoint_unique UNIQUE (organization_id)
);
SQL);
    }
}
