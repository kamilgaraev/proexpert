<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\CommercialWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CommercialWebhookTransactionRunner
{
    /**
     * @param  callable(): string  $callback
     */
    public function run(string $fingerprint, callable $callback): string
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $exception) {
            if ($this->isFingerprintUniqueViolation($exception)
                && CommercialWebhookEvent::query()->where('fingerprint', $fingerprint)->exists()) {
                return 'duplicate';
            }

            throw $exception;
        }
    }

    private function isFingerprintUniqueViolation(QueryException $exception): bool
    {
        if (! in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
            return false;
        }

        return str_contains($exception->getMessage(), 'commercial_webhook_events_fingerprint_unique')
            || str_contains($exception->getMessage(), 'commercial_webhook_events.fingerprint');
    }
}
