<?php

declare(strict_types=1);

namespace App\Filament\Support\EstimateGeneration;

final class FailureDiagnosticsPresenter
{
    private const PATTERNS = [
        'provider_code' => '/^[a-z][a-z0-9._-]{0,79}$/',
        'http_class' => '/^[1-5]xx$/',
        'http_code' => '/^(?:[1-5][0-9]{2})$/',
        'status' => '/^[a-z][a-z0-9_]{0,39}$/',
        'safe_code' => '/^[a-z][a-z0-9_]{0,79}$/',
        'retry_after_seconds' => '/^(?:0|[1-9][0-9]{0,4})$/',
        'attempt' => '/^(?:[1-9]|[1-9][0-9]{1,2}|1000)$/',
        'validation_code' => '/^[a-z][a-z0-9_]{0,79}$/',
        'storage_code' => '/^[a-z][a-z0-9_]{0,79}$/',
        'claim_status' => '/^(?:lost|expired|stale|busy)$/',
        'lineage_code' => '/^[a-z][a-z0-9_]{0,79}$/',
        'failure_fingerprint' => '/^sha256:[0-9a-f]{64}$/',
    ];

    /** @param array<string, mixed> $context
     * @return array<string, string>
     */
    public static function present(array $context): array
    {
        $result = [];
        foreach (self::PATTERNS as $key => $pattern) {
            $value = $context[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;
            if (preg_match($pattern, $value) !== 1) {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
