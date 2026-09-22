<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

final class CompletedWorkCorrectionRules
{
    public static function all(): array
    {
        return [
            'operation_key' => ['required', 'string', 'max:128'],
            'expected_version' => ['required', 'string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'source_event_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'quantity' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999'],
            'completed_quantity' => ['sometimes', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999.9999'],
            'price' => ['prohibited'],
            'total_amount' => ['prohibited'],
        ];
    }
}
