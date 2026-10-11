<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\SupplierSnapshotPrice;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SupplierSnapshotPriceTest extends TestCase
{
    public function test_supplier_rate_is_bound_to_material_unit_quantity_and_immutable_line_amount(): void
    {
        $source = $this->read($this->snapshot(), ['material_id' => 12, 'quantity' => '2', 'unit' => 'шт']);
        self::assertTrue($source['applicable']);
        self::assertSame('100.25', $source['unit_price']);
        self::assertSame(99, $source['supplier_request_line_id']);
        self::assertSame('excluded', $source['vat_mode']);
        self::assertFalse($source['delivery_included']);
        self::assertContains('delivery_allocation', $source['conditions_missing']);
        self::assertFalse($this->read($this->snapshot(), ['material_id' => 13, 'quantity' => '2', 'unit' => 'count'])['applicable']);
        self::assertFalse($this->read($this->snapshot(), ['material_id' => 12, 'quantity' => '3', 'unit' => 'count'])['applicable']);
        $corrupt = $this->snapshot();
        $corrupt['lines'][0]['unit_price'] = '500';
        self::assertNull($this->read($corrupt));
    }

    public function test_full_source_hash_changes_when_price_changes_even_if_the_source_identity_is_the_same(): void
    {
        $first = $this->read($this->snapshot());
        $changed = $this->snapshot();
        $changed['lines'][0]['unit_price'] = '200.25';
        $changed['lines'][0]['total_amount'] = $changed['subtotal_amount'] = '400.50';
        $changed['vat_amount'] = '84.10';
        $changed['total_amount'] = '504.60';
        $second = $this->read($changed);
        self::assertSame($first['source_reference'], $second['source_reference']);
        self::assertNotSame($first['source_hash'], $second['source_hash']);
    }

    public function test_dates_and_zero_delivery_are_not_inferred_from_a_default_or_invalid_value(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['valid_until'] = '2026-10-01';
        $snapshot['proposal_date'] = '2026-02-30';
        $snapshot['delivery_amount'] = '0.00';
        $snapshot['vat_amount'] = '40.10';
        $snapshot['total_amount'] = '240.60';
        $source = $this->read($snapshot);
        self::assertTrue($source['stale']);
        self::assertFalse($source['stale_accepted']);
        self::assertNull($source['as_of_date']);
        self::assertTrue($source['delivery_included']);
        self::assertNotContains('delivery_allocation', $source['conditions_missing']);
        $snapshot['delivery_amount'] = null;
        self::assertContains('delivery_allocation', $this->read($snapshot)['conditions_missing']);
    }

    public function test_corrupt_header_totals_and_future_proposal_do_not_become_an_applicable_price(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['total_amount'] = '264.61';
        self::assertNull($this->read($snapshot));
        $snapshot = $this->snapshot();
        $snapshot['vat_amount'] = '44.11';
        self::assertNull($this->read($snapshot));
        $snapshot = $this->snapshot();
        $snapshot['proposal_date'] = '2027-01-01';
        self::assertFalse($this->read($snapshot, ['material_id' => 12, 'quantity' => '2', 'unit' => 'count'])['applicable']);
    }

    private function read(array $snapshot, array $target = []): ?array
    {
        return (new SupplierSnapshotPrice)->extract($snapshot, 15, 'supplier:1:15', new DateTimeImmutable('2026-10-11'), $target);
    }

    private function snapshot(): array
    {
        return ['proposal_date' => '2026-10-10', 'valid_until' => '2026-10-30', 'currency' => 'RUB',
            'subtotal_amount' => '200.50', 'delivery_amount' => '20.00', 'vat_amount' => '44.10', 'total_amount' => '264.60',
            'vat_mode' => 'excluded', 'vat_rate' => '20.00', 'delivery_terms' => 'Доставка отдельной строкой',
            'lines' => [['id' => 15, 'supplier_request_line_id' => 99, 'material_id' => 12, 'name' => 'Насос',
                'quantity' => '2.000', 'unit' => 'шт', 'unit_price' => '100.25', 'total_amount' => '200.50']]];
    }
}
