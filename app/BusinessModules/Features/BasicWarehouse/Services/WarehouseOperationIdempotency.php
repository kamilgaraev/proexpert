<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

final class WarehouseOperationIdempotency
{
    public static function fingerprint(string $operation, array $data): string
    {
        return hash('sha256', json_encode([
            'operation' => $operation,
            'payload' => self::normalize($data),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalize(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if ($key === 'idempotency_key') {
                continue;
            }

            if (is_array($value)) {
                $normalized[$key] = self::normalize($value);

                continue;
            }

            $normalized[$key] = match (true) {
                in_array($key, ['quantity', 'price'], true) => self::decimal($value),
                is_string($value) => trim($value),
                default => $value,
            };
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private static function decimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
