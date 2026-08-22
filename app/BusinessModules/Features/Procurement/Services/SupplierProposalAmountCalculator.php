<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\SupplierProposalVatModeEnum;

final class SupplierProposalAmountCalculator
{
    public static function calculate(array $data): array
    {
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $subtotal = $items === []
            ? self::money($data['subtotal_amount'] ?? 0)
            : self::itemsSubtotal($items);
        $delivery = self::money($data['delivery_amount'] ?? 0);
        $base = self::money($subtotal + $delivery);
        $vatMode = (string) ($data['vat_mode'] ?? SupplierProposalVatModeEnum::INCLUDED->value);
        $vatRate = max(0.0, (float) ($data['vat_rate'] ?? 0));

        $vat = match ($vatMode) {
            SupplierProposalVatModeEnum::EXCLUDED->value => self::money($base * $vatRate / 100),
            SupplierProposalVatModeEnum::INCLUDED->value => $vatRate > 0
                ? self::money($base * $vatRate / (100 + $vatRate))
                : 0.0,
            default => 0.0,
        };
        $total = $vatMode === SupplierProposalVatModeEnum::EXCLUDED->value
            ? self::money($base + $vat)
            : $base;

        return [
            'subtotal_amount' => $subtotal,
            'delivery_amount' => $delivery,
            'vat_amount' => $vat,
            'total_amount' => $total,
        ];
    }

    public static function lineTotal(mixed $quantity, mixed $unitPrice): float
    {
        return self::money((float) $quantity * (float) $unitPrice);
    }

    private static function itemsSubtotal(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            $sum += self::lineTotal($item['quantity'] ?? 0, $item['unit_price'] ?? 0);
        }

        return self::money($sum);
    }

    private static function money(mixed $value): float
    {
        return round((float) $value, 2, PHP_ROUND_HALF_UP);
    }
}
