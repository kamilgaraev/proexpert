<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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
}
