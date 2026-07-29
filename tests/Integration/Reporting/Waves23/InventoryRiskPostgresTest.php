<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\DB;

final class InventoryRiskPostgresTest extends Waves23PostgresTestCase
{
    public function test_transfer_recurrence_opening_and_value_metrics_are_database_fenced(): void
    {
        $this->assertTriggerExists('warehouse_inventory_events', 'warehouse_inventory_events_append_only');
        $this->assertTriggerExists('warehouse_inventory_events', 'warehouse_inventory_transfer_pair');
        self::assertSame('character varying', $this->column('warehouse_inventory_events', 'opening_basis')->data_type);
        self::assertNotNull($this->column('inventory_risk_rows', 'cost_turnover'));
        self::assertNotNull($this->column('inventory_risk_rows', 'days_on_hand'));
        self::assertTrue(DB::table('pg_constraint')
            ->where('conname', 'daily_balance_equation_check')
            ->exists());
    }
}
