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
                    $record = \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::query()
                        ->firstOrCreate(
                            ['event_key' => $eventKey],
                            [
                                'performance_act_id' => 991003,
                                'contract_id' => 881003,
                                'project_id' => 771003,
                                'organization_id' => 661003,
                                'active' => true,
                                'amount' => '100.00',
                                'status' => 'approved',
                                'occurred_at' => '2026-07-30 10:00:00+00',
                                'recorded_at' => '2026-07-30 10:00:01+00',
                                'source_hash' => hash('sha256', 'race-accepted-work-event'),
                            ],
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
}
