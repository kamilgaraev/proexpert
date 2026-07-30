<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\DB;

final class SupplyReliabilityPostgresTest extends Waves23PostgresTestCase
{
    public function test_original_promise_events_and_otif_denominators_are_database_fenced(): void
    {
        $this->assertTriggerExists(
            'purchase_order_promise_versions',
            'purchase_order_promise_versions_append_only',
        );
        $this->assertTriggerExists(
            'sent_purchase_order_line_owners',
            'sent_purchase_order_line_owners_append_only',
        );
        $this->assertTriggerExists('supply_lifecycle_events', 'supply_lifecycle_events_append_only');
        self::assertSame('boolean', $this->column('supply_reliability_rows', 'stable_in_full')->data_type);
        self::assertNotNull($this->column('supply_reliability_rows', 'quantity_otif_denominator'));
        self::assertNotNull($this->column('supply_reliability_rows', 'value_otif_denominator_minor'));
        self::assertNotNull($this->column('supply_reliability_rows', 'lifecycle_event_ids'));
        self::assertSame(
            'timestamp with time zone',
            $this->column('purchase_orders', 'sent_at')->data_type,
        );
        self::assertSame(
            'timestamp with time zone',
            $this->column('purchase_orders', 'confirmed_at')->data_type,
        );
        self::assertSame(
            'timestamp with time zone',
            $this->column('purchase_orders', 'cancelled_at')->data_type,
        );
        self::assertTrue(DB::table('pg_constraint')
            ->where('conname', 'supply_promise_source_version_unique')
            ->exists());
        self::assertSame(
            'bigint',
            $this->column('supply_reliability_backfill_watermarks', 'target_item_id')->data_type,
        );
        self::assertNotNull(
            $this->column('supply_reliability_backfill_watermarks', 'completed_item_id'),
        );
    }
}
