<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class IntercompanyContractFlowProjectionPostgresTest extends TestCase
{
    #[Test]
    public function holding_fact_version_is_append_only_in_postgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_allocation_fact_versions')->insertGetId([
            'organization_id' => 760001,
            'holding_id' => 760001,
            'hierarchy_version' => hash('sha256', 'hierarchy'),
            'contributor_organization_id' => 760001,
            'counterparty_organization_id' => null,
            'project_id' => 750001,
            'contract_id' => 740001,
            'allocation_id' => 730001,
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => 'contract',
            'source_id' => 730001,
            'source_version' => 1,
            'source_schema_version' => 'holding_allocation_facts_v1',
            'monetary_basis' => 'contracted',
            'tax_basis' => 'contract_total',
            'amount_minor' => 10000,
            'currency' => 'RUB',
            'currency_source' => 'payment_document_consensus',
            'recognized_on' => '2026-07-30',
            'flow_class' => 'external',
            'allocated_amount_minor' => 10000,
            'allocated_percentage' => null,
            'contract_amount_minor' => 10000,
            'source_refs' => json_encode([], JSON_THROW_ON_ERROR),
            'source_hash' => hash('sha256', 'holding-fact'),
            'projected_at' => '2026-07-30 12:00:00+00',
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_allocation_fact_versions')
            ->where('id', $id)
            ->update(['amount_minor' => 99999]);
    }
}
