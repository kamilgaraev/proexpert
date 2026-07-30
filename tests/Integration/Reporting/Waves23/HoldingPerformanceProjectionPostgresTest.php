<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgres')]
final class HoldingPerformanceProjectionPostgresTest extends TestCase
{
    #[Test]
    public function immutable_contract_version_evidence_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_contract_version_evidence')->insertGetId([
            'allocation_history_id' => 991001,
            'contract_id' => 881001,
            'organization_id' => 771001,
            'total_amount' => '1234.56',
            'contractor_id' => null,
            'counterparty_organization_id' => null,
            'recorded_at' => '2026-07-30 10:00:00+00',
            'source_hash' => hash('sha256', 'immutable-contract-version'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_contract_version_evidence')
            ->where('id', $id)
            ->update(['total_amount' => '9999.99']);
    }

    #[Test]
    public function accepted_work_event_version_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_accepted_work_event_versions')->insertGetId([
            'event_key' => 'test-event-'.str()->uuid(),
            'performance_act_id' => 991002,
            'contract_id' => 881002,
            'project_id' => 771002,
            'organization_id' => 661002,
            'active' => true,
            'amount' => '100.00',
            'status' => 'approved',
            'occurred_at' => '2026-07-30 10:00:00+00',
            'recorded_at' => '2026-07-30 10:00:01+00',
            'source_hash' => hash('sha256', 'immutable-accepted-work-event'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_accepted_work_event_versions')
            ->where('id', $id)
            ->update(['active' => false]);
    }

    #[Test]
    public function accepted_work_event_identity_converges_under_process_race(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $suffix = bin2hex(random_bytes(6));
        $eventKey = 'race-event-'.$suffix;
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'accepted-work-event-race-'.$suffix,
        );
        $children = [];

        try {
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($eventKey): array {
                    $contract = new \App\Models\Contract;
                    $contract->setRawAttributes(['id' => 881003, 'organization_id' => 661003], true);
                    $act = new \App\Models\ContractPerformanceAct;
                    $act->setRawAttributes([
                        'id' => 991003,
                        'contract_id' => 881003,
                        'project_id' => 771003,
                        'is_approved' => true,
                        'amount' => '100.00',
                        'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
                    ], true);
                    $act->setRelation('contract', $contract);
                    $record = \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::record(
                        $act,
                        true,
                        new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
                        $eventKey,
                        true,
                    );

                    return ['id' => (int) $record->getKey()];
                });
            }
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame($harness->result(1)['id'], $harness->result(2)['id']);
            self::assertSame(
                1,
                DB::table('holding_accepted_work_event_versions')->where('event_key', $eventKey)->count(),
            );
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    #[Test]
    public function public_accepted_work_record_detects_conflicting_payload_under_process_race(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $suffix = bin2hex(random_bytes(6));
        $eventKey = 'race-conflict-'.$suffix;
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'accepted-work-conflict-'.$suffix,
        );
        $children = [];

        try {
            foreach ([1 => '100.00', 2 => '200.00'] as $worker => $amount) {
                $children[] = $harness->spawn($worker, static function () use ($eventKey, $amount): array {
                    $contract = new \App\Models\Contract;
                    $contract->setRawAttributes(['id' => 881004, 'organization_id' => 661004], true);
                    $act = new \App\Models\ContractPerformanceAct;
                    $act->setRawAttributes([
                        'id' => 991004,
                        'contract_id' => 881004,
                        'project_id' => 771004,
                        'is_approved' => true,
                        'amount' => $amount,
                        'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
                    ], true);
                    $act->setRelation('contract', $contract);
                    try {
                        \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::record(
                            $act,
                            true,
                            new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
                            $eventKey,
                            true,
                        );

                        return ['created' => true, 'conflict' => false];
                    } catch (\InvalidArgumentException $exception) {
                        return ['created' => false, 'conflict' => $exception->getMessage() === 'accepted_work_event_conflict'];
                    }
                });
            }
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];

            $results = [$harness->result(1), $harness->result(2)];
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['created'])));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['conflict'])));
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }
}
