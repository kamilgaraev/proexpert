<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class IntercompanyContractFlowProjectionPostgresTest extends TestCase
{
    #[Test]
    public function independent_intercompany_snapshot_and_row_grain_are_present(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        self::assertTrue(Schema::hasColumns('intercompany_contract_flow_snapshots', [
            'organization_id',
            'holding_id',
            'source_hash',
            'query_hash',
            'hierarchy_watermark',
            'allocation_watermark',
        ]));
        self::assertTrue(Schema::hasColumns('intercompany_contract_flow_rows', [
            'organization_id',
            'snapshot_id',
            'project_id',
            'allocation_id',
            'counterparty_organization_id',
            'currency',
            'period_start',
            'internal_minor',
            'external_minor',
            'unclassified_minor',
            'total_minor',
            'internal_share',
            'external_share',
            'unclassified_share',
            'linked_spread_minor',
            'row_key',
        ]));
    }
}
