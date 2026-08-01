<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Support;

final class ProcurementAwardVersionProjection
{
    public static function proposal(array $commercialSnapshot): array
    {
        $lines = is_array($commercialSnapshot['lines'] ?? null) ? $commercialSnapshot['lines'] : [];

        return [
            'subtotal_amount' => $commercialSnapshot['subtotal_amount'] ?? null,
            'delivery_amount' => $commercialSnapshot['delivery_amount'] ?? null,
            'vat_amount' => $commercialSnapshot['vat_amount'] ?? null,
            'total_amount' => $commercialSnapshot['total_amount'] ?? null,
            'currency' => $commercialSnapshot['currency'] ?? null,
            'vat_mode' => $commercialSnapshot['vat_mode'] ?? null,
            'vat_rate' => $commercialSnapshot['vat_rate'] ?? null,
            'valid_until' => $commercialSnapshot['valid_until'] ?? null,
            'delivery_due_date' => $commercialSnapshot['delivery_due_date'] ?? null,
            'lead_time_days' => $commercialSnapshot['lead_time_days'] ?? null,
            'lines' => array_values(array_map(static fn (mixed $line): array => is_array($line) ? [
                'id' => $line['id'] ?? null,
                'supplier_request_line_id' => $line['supplier_request_line_id'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'unit' => $line['unit'] ?? null,
            ] : [], $lines)),
        ];
    }

    public static function requestLines(array $lineSnapshot): array
    {
        return array_values(array_map(static fn (mixed $line): array => is_array($line) ? [
            'id' => $line['id'] ?? null,
            'quantity' => $line['quantity'] ?? null,
            'unit' => $line['unit'] ?? null,
        ] : [], $lineSnapshot));
    }

    public static function proposalHash(array $commercialSnapshot): string
    {
        return ProcurementAwardCanonicalizer::hash(self::proposal($commercialSnapshot));
    }

    public static function requestHash(array $requestSnapshot, array $lineSnapshot): string
    {
        return ProcurementAwardCanonicalizer::hash([
            'request_id' => $requestSnapshot['id'] ?? null,
            'purchase_request_id' => $requestSnapshot['purchase_request_id'] ?? null,
            'lines' => self::requestLines($lineSnapshot),
        ]);
    }
}
