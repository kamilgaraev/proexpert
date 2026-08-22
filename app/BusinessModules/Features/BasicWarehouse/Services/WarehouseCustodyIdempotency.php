<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

final class WarehouseCustodyIdempotency
{
    public static function fingerprint(string $operation, array $data): string
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if ($key === 'idempotency_key') {
                continue;
            }

            $normalized[$key] = match ($key) {
                'quantity' => self::decimal($value, 6),
                'document_number', 'reason' => trim((string) $value),
                default => is_numeric($value) ? (int) $value : $value,
            };
        }
        ksort($normalized);

        return hash('sha256', json_encode([
            'operation' => $operation,
            'payload' => $normalized,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function decimal(mixed $value, int $scale): string
    {
        return rtrim(rtrim(number_format((float) $value, $scale, '.', ''), '0'), '.');
    }
}
