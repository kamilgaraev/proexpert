<?php

declare(strict_types=1);

namespace App\Services\OneCExchange\Testing;

final class OneCDocumentExchangeFixtureFactory
{
    public static function contract(int $id, int $version): OneCDocumentExchangePayload
    {
        return self::document('contracts', 'contract', $id, $version, [
            'number' => "CNT-{$id}",
            'amount' => 250000,
            'currency' => 'RUB',
        ]);
    }

    public static function act(int $id, int $version): OneCDocumentExchangePayload
    {
        return self::document('acts', 'act', $id, $version, [
            'number' => "ACT-{$id}",
            'amount' => 120000,
            'period' => '2026-06',
        ]);
    }

    public static function payment(int $id, int $version): OneCDocumentExchangePayload
    {
        return self::document('payment_documents', 'payment_document', $id, $version, [
            'number' => "PAY-{$id}",
            'amount' => 90000,
            'currency' => 'RUB',
        ]);
    }

    public static function procurementOrder(int $id, int $version): OneCDocumentExchangePayload
    {
        return self::document('procurement_documents', 'purchase_order', $id, $version, [
            'number' => "PO-{$id}",
            'amount' => 180000,
        ]);
    }

    public static function warehouseMovement(int $id, int $version): OneCDocumentExchangePayload
    {
        return self::document('warehouse_documents', 'warehouse_movement', $id, $version, [
            'number' => "WH-{$id}",
            'movement_type' => 'receipt',
        ]);
    }

    private static function document(
        string $scope,
        string $entityType,
        int $id,
        int $version,
        array $preview
    ): OneCDocumentExchangePayload {
        return new OneCDocumentExchangePayload(
            scope: $scope,
            entityType: $entityType,
            entityId: $id,
            version: $version,
            idempotencyKey: "org:{$entityType}:{$id}:{$version}",
            safePayloadPreview: $preview
        );
    }
}
