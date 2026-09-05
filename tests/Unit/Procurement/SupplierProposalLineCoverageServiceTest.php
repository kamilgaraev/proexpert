<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\Services\SupplierProposalLineCoverageService;
use PHPUnit\Framework\TestCase;

final class SupplierProposalLineCoverageServiceTest extends TestCase
{
    public function test_purchase_quantity_cannot_override_the_sent_request_quantity(): void
    {
        $result = (new SupplierProposalLineCoverageService)->evaluatePurchase(
            [['id' => 10, 'quantity' => '10', 'unit' => 'шт']],
            [['id' => 25, 'purchase_request_line_id' => 10, 'quantity' => '5', 'unit' => 'шт']],
            [['supplier_request_line_id' => 25, 'quantity' => '10', 'unit' => 'шт']],
        );
        self::assertFalse($result['complete']);
        self::assertContains('incomplete_request_line_coverage', $result['issues']);
    }

    public function test_non_positive_quantities_never_cover_a_requirement(): void
    {
        foreach (['0', '-1'] as $quantity) {
            $result = (new SupplierProposalLineCoverageService)->evaluate(
                [['id' => 1, 'quantity' => $quantity, 'unit' => 'кг']],
                [['supplier_request_line_id' => 1, 'quantity' => $quantity, 'unit' => 'кг']],
            );
            self::assertFalse($result['complete']);
            self::assertFalse($result['lines'][0]['covered']);
        }
    }

    public function test_malformed_purchase_mapping_is_rejected_without_a_type_error(): void
    {
        $result = (new SupplierProposalLineCoverageService)->evaluatePurchase(
            [['id' => 10, 'quantity' => '1', 'unit' => 'кг']],
            [['id' => [], 'purchase_request_line_id' => 10]],
            [['supplier_request_line_id' => [], 'quantity' => '1', 'unit' => 'кг']],
        );
        self::assertFalse($result['complete']);
        self::assertContains('invalid_request_line', $result['issues']);
    }

    public function test_duplicate_mapping_cannot_silently_replace_a_request_line(): void
    {
        $result = (new SupplierProposalLineCoverageService)->evaluatePurchase(
            [['id' => 10, 'quantity' => '1', 'unit' => 'кг']],
            [['id' => 25, 'purchase_request_line_id' => 10], ['id' => 25, 'purchase_request_line_id' => 10]],
            [['supplier_request_line_id' => 25, 'quantity' => '1', 'unit' => 'кг']],
        );
        self::assertFalse($result['complete']);
        self::assertContains('duplicate_request_line_identity', $result['issues']);
    }

    public function test_supplier_request_subset_does_not_cover_the_purchase_requirement(): void
    {
        $result = (new SupplierProposalLineCoverageService)->evaluatePurchase(
            [['id' => 10, 'quantity' => '100', 'unit' => 'кг']],
            [['id' => 25, 'purchase_request_line_id' => 10, 'quantity' => '40', 'unit' => 'кг']],
            [['supplier_request_line_id' => 25, 'quantity' => '40', 'unit' => 'кг']],
        );

        self::assertFalse($result['complete']);
        self::assertSame('100', $result['lines'][0]['required_quantity']);
        self::assertSame('40', $result['lines'][0]['covered_quantity']);
    }

    public function test_missing_purchase_line_is_not_hidden_by_a_complete_supplier_request(): void
    {
        $result = (new SupplierProposalLineCoverageService)->evaluatePurchase(
            [['id' => 10, 'quantity' => '100', 'unit' => 'кг'], ['id' => 11, 'quantity' => '2', 'unit' => 'шт']],
            [['id' => 25, 'purchase_request_line_id' => 10, 'quantity' => '100', 'unit' => 'кг']],
            [['supplier_request_line_id' => 25, 'quantity' => '100', 'unit' => 'кг']],
        );

        self::assertFalse($result['complete']);
        self::assertCount(2, $result['lines']);
        self::assertNull($result['lines'][1]['covered_quantity']);
    }

    public function test_complete_offer_maps_supplier_line_identity_to_purchase_line(): void
    {
        $result = (new SupplierProposalLineCoverageService)->evaluatePurchase(
            [['id' => 10, 'quantity' => '100.000', 'unit' => 'кг']],
            [['id' => 25, 'purchase_request_line_id' => 10, 'quantity' => '100', 'unit' => 'кг']],
            [['supplier_request_line_id' => 25, 'quantity' => '100', 'unit' => 'кг']],
        );

        self::assertTrue($result['complete']);
        self::assertSame(10, $result['lines'][0]['purchase_request_line_id']);
    }
}
