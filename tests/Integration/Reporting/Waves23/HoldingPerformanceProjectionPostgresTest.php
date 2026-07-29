<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class HoldingPerformanceProjectionPostgresTest extends TestCase
{
    #[Test]
    public function immutable_fact_and_snapshot_grains_are_present(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        self::assertTrue(Schema::hasColumns('holding_allocation_fact_versions', [
            'organization_id',
            'holding_id',
            'hierarchy_version',
            'source_type',
            'source_id',
            'source_version',
            'monetary_basis',
            'amount_minor',
            'currency',
            'recognized_on',
            'flow_class',
            'linked_incoming_minor',
            'linked_outgoing_minor',
            'source_schema_version',
            'source_hash',
        ]));
        self::assertTrue(Schema::hasColumns('holding_allocation_projection_gaps', [
            'organization_id',
            'holding_id',
            'hierarchy_version',
            'source_type',
            'source_id',
            'source_version',
            'monetary_basis',
            'missing_fields',
            'resolved_at',
        ]));
        self::assertTrue(Schema::hasColumns('holding_performance_snapshots', [
            'organization_id',
            'source_hash',
            'query_hash',
            'hierarchy_watermark',
            'allocation_watermark',
            'act_watermark',
            'payment_watermark',
        ]));
        self::assertTrue(Schema::hasColumns('holding_performance_rows', [
            'organization_id',
            'snapshot_id',
            'contributor_organization_id',
            'project_id',
            'currency',
            'period_start',
            'monetary_basis',
            'row_key',
        ]));
    }
}
