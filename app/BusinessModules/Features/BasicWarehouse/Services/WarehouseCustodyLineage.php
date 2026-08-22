<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

final class WarehouseCustodyLineage
{
    private const BATCH_PREFIX = 'custody-issue:';

    public static function batchNumber(string $idempotencyKey): string
    {
        return self::BATCH_PREFIX.$idempotencyKey;
    }

    public static function allocations(array $sourceDetails): array
    {
        $allocations = [];
        foreach ($sourceDetails as $source) {
            $batchNumber = (string) ($source['batch_number'] ?? '');
            if (! str_starts_with($batchNumber, self::BATCH_PREFIX)) {
                continue;
            }

            $allocations[] = [
                'idempotency_key' => substr($batchNumber, strlen(self::BATCH_PREFIX)),
                'quantity' => (float) $source['quantity'],
            ];
        }

        return $allocations;
    }
}
