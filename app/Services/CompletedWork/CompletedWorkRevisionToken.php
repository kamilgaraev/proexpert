<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use App\Models\CompletedWork;

final class CompletedWorkRevisionToken
{
    public static function forWork(CompletedWork $work): string
    {
        $additionalInfo = $work->additional_info ?? [];
        if (is_array($additionalInfo)) {
            self::sortRecursive($additionalInfo);
        }

        return hash('sha256', json_encode([
            'id' => (int) $work->id,
            'quantity' => (string) $work->quantity,
            'completed_quantity' => $work->completed_quantity !== null ? (string) $work->completed_quantity : null,
            'price' => $work->price !== null ? (string) $work->price : null,
            'total_amount' => $work->total_amount !== null ? (string) $work->total_amount : null,
            'status' => (string) $work->status,
            'additional_info' => $additionalInfo,
            'updated_at' => $work->updated_at?->format('Y-m-d\\TH:i:s.uP'),
        ], JSON_THROW_ON_ERROR));
    }

    private static function sortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
