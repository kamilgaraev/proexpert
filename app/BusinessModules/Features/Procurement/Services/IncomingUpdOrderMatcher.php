<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\DTOs\IncomingUpdOrderMatchResult;
use App\BusinessModules\Features\Procurement\DTOs\IncomingUpdValidationResult;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

final class IncomingUpdOrderMatcher
{
    public function match(
        PurchaseOrder $order,
        IncomingUpdValidationResult $document,
    ): IncomingUpdOrderMatchResult {
        $order->loadMissing([
            'organization',
            'supplier',
            'externalSupplierContact',
            'supplierParty',
            'items.receiptLines',
        ]);

        $errors = $document->errors;
        $expectedBuyerInn = $this->normalizedIdentifier($order->organization?->tax_number);
        $expectedSellerInn = $this->supplierInn($order);

        if ($expectedBuyerInn === null || $expectedBuyerInn !== $this->normalizedIdentifier($document->buyer['inn'] ?? null)) {
            $errors[] = ['code' => 'buyer_mismatch'];
        }
        if ($expectedSellerInn === null || $expectedSellerInn !== $this->normalizedIdentifier($document->seller['inn'] ?? null)) {
            $errors[] = ['code' => 'seller_mismatch'];
        }
        if ($this->currencyCode((string) $order->currency) !== $document->currencyCode) {
            $errors[] = ['code' => 'currency_mismatch'];
        }

        $matchedItems = [];
        $usedOrderItemIds = [];

        foreach ($document->items as $documentItem) {
            $lineNumber = $documentItem['line_number'] ?? null;
            $candidates = $this->matchingOrderItems($order->items, $documentItem);

            if ($candidates->count() !== 1) {
                $errors[] = [
                    'code' => $candidates->isEmpty() ? 'item_not_found' : 'item_ambiguous',
                    'line_number' => $lineNumber,
                ];

                continue;
            }

            /** @var PurchaseOrderItem $orderItem */
            $orderItem = $candidates->first();
            $orderItemId = (int) $orderItem->getKey();
            if (in_array($orderItemId, $usedOrderItemIds, true)) {
                $errors[] = ['code' => 'item_duplicate', 'line_number' => $lineNumber];

                continue;
            }
            $usedOrderItemIds[] = $orderItemId;

            $quantity = (float) ($documentItem['quantity'] ?? 0);
            $remaining = (float) $orderItem->quantity
                - (float) $orderItem->receiptLines->sum('quantity_received');
            if ($quantity <= 0 || $quantity > $remaining + 0.000000001) {
                $errors[] = ['code' => 'item_quantity_exceeded', 'line_number' => $lineNumber];
            }
            if (! $this->sameMoney($documentItem['price'] ?? null, $orderItem->unit_price)) {
                $errors[] = ['code' => 'item_price_mismatch', 'line_number' => $lineNumber];
            }

            $matchedItems[] = [
                ...$documentItem,
                'purchase_order_item_id' => $orderItemId,
            ];
        }

        return new IncomingUpdOrderMatchResult(
            matchedItems: $matchedItems,
            errors: $errors,
            warnings: $document->warnings,
        );
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @param  array<string, string|null>  $documentItem
     * @return Collection<int, PurchaseOrderItem>
     */
    private function matchingOrderItems(Collection $items, array $documentItem): Collection
    {
        $name = $this->normalizeText($documentItem['name'] ?? null);
        $unit = $documentItem['unit_name'] ?? null;

        return $items
            ->filter(fn (PurchaseOrderItem $item): bool => $this->normalizeText($item->material_name) === $name
                && ProcurementUnitCompatibility::matches((string) $item->unit, $unit, $unit)
            )
            ->values();
    }

    private function supplierInn(PurchaseOrder $order): ?string
    {
        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];

        foreach ([
            $snapshot['tax_id'] ?? null,
            $order->supplierParty?->tax_id,
            $order->externalSupplierContact?->tax_number,
            $order->supplier?->inn,
            $order->supplier?->tax_number,
        ] as $value) {
            $normalized = $this->normalizedIdentifier($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function currencyCode(string $currency): ?string
    {
        $normalized = strtoupper(trim($currency));

        return match ($normalized) {
            'RUB', 'RUR', '643' => '643',
            default => preg_match('/^\d{3}$/', $normalized) === 1 ? $normalized : null,
        };
    }

    private function normalizedIdentifier(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);

        return is_string($normalized) && $normalized !== '' ? $normalized : null;
    }

    private function normalizeText(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        return mb_strtolower($normalized ?? '', 'UTF-8');
    }

    private function sameMoney(mixed $first, mixed $second): bool
    {
        return is_numeric($first)
            && is_numeric($second)
            && abs((float) $first - (float) $second) < 0.005;
    }
}
