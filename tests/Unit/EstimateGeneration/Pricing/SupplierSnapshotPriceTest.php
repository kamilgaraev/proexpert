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

    public function test_scaled_supplier_units_keep_the_quoted_quantity_in_the_work_unit(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['lines'][0] = [...$snapshot['lines'][0], 'quantity' => '1', 'unit' => '100 м²', 'unit_price' => '200.50'];
        $price = $this->read($snapshot, ['material_id' => 12, 'quantity' => '100', 'unit' => 'm2']);
        self::assertTrue($price['applicable']);
        $converted = (new \App\BusinessModules\Addons\EstimateGeneration\Pricing\ConvertEvaluationPriceUnit)->convert($price, 'm2');
        self::assertSame('2.0050', $converted['unit_price']);
        self::assertSame('100', $converted['price_quantity']);
        self::assertSame('1', $converted['source_price_quantity']);
        self::assertFalse($this->read($snapshot, ['material_id' => 12, 'quantity' => '101', 'unit' => 'm2'])['applicable']);
    }

    public function test_batch_discovery_preserves_the_existing_canonical_source_hash(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['lines'][] = [...$snapshot['lines'][0], 'id' => 16, 'material_id' => 13, 'quantity' => '3', 'unit_price' => '50', 'total_amount' => '150'];
        $snapshot = [...$snapshot, 'subtotal_amount' => '350.50', 'vat_amount' => '74.10', 'total_amount' => '444.60'];
        $sources = (new SupplierSnapshotPrice)->extractMany($snapshot, [15 => 'supplier:1:15', 16 => 'supplier:1:16'],
            new DateTimeImmutable('2026-10-11'), ['material_id' => 12, 'quantity' => '2', 'unit' => 'count']);
        self::assertCount(2, $sources);
        self::assertTrue($sources[15]['applicable']);
        self::assertFalse($sources[16]['applicable']);
        self::assertSame(hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode([
            'header' => $snapshot, 'selected_line' => $snapshot['lines'][0],
        ])), $sources[15]['source_hash']);
    }

    public function test_duplicate_or_oversized_source_lines_are_rejected_instead_of_silently_truncated(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['lines'][] = $snapshot['lines'][0];
        self::assertNull($this->read($snapshot));
        $snapshot['lines'] = array_fill(0, SupplierSnapshotPrice::MAX_LINES + 1, $snapshot['lines'][0]);
        self::assertNull($this->read($snapshot));
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
