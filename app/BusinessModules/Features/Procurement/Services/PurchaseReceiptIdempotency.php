<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

final class PurchaseReceiptIdempotency
{
    public static function fingerprint(int $warehouseId, array $items, array $receiptData): string
    {
        $documentMode = (string) ($receiptData['document_mode'] ?? 'torg12_paper');
        $normalizedItems = array_map(
            static fn (array $item): array => [
                'item_id' => (int) $item['item_id'],
                'quantity_received' => self::decimal($item['quantity_received'], 6),
                'price' => self::decimal($item['price'], 4),
            ],
            $items,
        );

        usort(
            $normalizedItems,
            static fn (array $left, array $right): int => $left['item_id'] <=> $right['item_id'],
        );

        return hash('sha256', json_encode([
            'warehouse_id' => $warehouseId,
            'items' => $normalizedItems,
            'receipt_date' => (string) ($receiptData['receipt_date'] ?? ''),
            'notes' => trim((string) ($receiptData['notes'] ?? '')),
            'document_mode' => $documentMode,
            'receipt_document_id' => $documentMode === 'upd_xml'
                ? (int) ($receiptData['receipt_document_id'] ?? 0)
                : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function decimal(mixed $value, int $scale): string
    {
        return rtrim(rtrim(number_format((float) $value, $scale, '.', ''), '0'), '.');
    }
}
