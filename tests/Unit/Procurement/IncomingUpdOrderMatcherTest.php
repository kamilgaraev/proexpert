<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement;

use App\BusinessModules\Features\Procurement\DTOs\IncomingUpdValidationResult;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\Procurement\Services\IncomingUpdOrderMatcher;
use App\Models\Organization;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class IncomingUpdOrderMatcherTest extends TestCase
{
    public function test_matches_parties_currency_and_lines_to_purchase_order(): void
    {
        $result = (new IncomingUpdOrderMatcher)->match($this->order(), $this->upd());

        self::assertTrue($result->isValid());
        self::assertSame([], $result->errors);
        self::assertSame(41, $result->matchedItems[0]['purchase_order_item_id']);
    }

    public function test_rejects_foreign_buyer(): void
    {
        $upd = $this->upd(buyerInn: '7701000001');

        $result = (new IncomingUpdOrderMatcher)->match($this->order(), $upd);

        self::assertFalse($result->isValid());
        self::assertContains('buyer_mismatch', array_column($result->errors, 'code'));
    }

    public function test_rejects_quantity_above_remaining_order_amount(): void
    {
        $upd = $this->upd(quantity: '101');

        $result = (new IncomingUpdOrderMatcher)->match($this->order(), $upd);

        self::assertFalse($result->isValid());
        self::assertContains('item_quantity_exceeded', array_column($result->errors, 'code'));
    }

    public function test_rejects_price_that_differs_from_order(): void
    {
        $upd = $this->upd(price: '16.00');

        $result = (new IncomingUpdOrderMatcher)->match($this->order(), $upd);

        self::assertFalse($result->isValid());
        self::assertContains('item_price_mismatch', array_column($result->errors, 'code'));
    }

    private function order(): PurchaseOrder
    {
        $order = new PurchaseOrder([
            'organization_id' => 7,
            'currency' => 'RUB',
            'supplier_snapshot' => [
                'display_name' => 'ООО Поставщик',
                'tax_id' => '7712345678',
            ],
        ]);
        $order->setRelation('organization', new Organization([
            'name' => 'ООО МОСТ',
            'tax_number' => '1654321098',
        ]));
        $order->setRelation('supplier', null);
        $order->setRelation('externalSupplierContact', null);
        $order->setRelation('supplierParty', null);

        $item = new PurchaseOrderItem([
            'material_name' => 'Цемент М500, мешок 50 кг',
            'quantity' => '100',
            'unit' => 'кг',
            'unit_price' => '15.00',
        ]);
        $item->setAttribute('id', 41);
        $item->setRelation('receiptLines', new Collection);
        $order->setRelation('items', new Collection([$item]));

        return $order;
    }

    private function upd(
        string $buyerInn = '1654321098',
        string $quantity = '100',
        string $price = '15.00',
    ): IncomingUpdValidationResult {
        return new IncomingUpdValidationResult(
            fileId: 'file-id',
            formatVersion: '5.03',
            function: 'ДОП',
            number: 'УПД-2026-1',
            date: '04.09.2026',
            currencyCode: '643',
            seller: ['name' => 'ООО Поставщик', 'inn' => '7712345678', 'kpp' => '771201001'],
            buyer: ['name' => 'ООО МОСТ', 'inn' => $buyerInn, 'kpp' => '165401001'],
            items: [[
                'line_number' => '1',
                'name' => 'Цемент М500, мешок 50 кг',
                'unit_code' => '166',
                'unit_name' => 'кг',
                'quantity' => $quantity,
                'price' => $price,
                'without_vat' => '1500.00',
                'vat_rate' => '20%',
                'vat_amount' => '300.00',
                'with_vat' => '1800.00',
            ]],
            totals: ['without_vat' => '1500.00', 'vat_amount' => '300.00', 'with_vat' => '1800.00'],
        );
    }
}
