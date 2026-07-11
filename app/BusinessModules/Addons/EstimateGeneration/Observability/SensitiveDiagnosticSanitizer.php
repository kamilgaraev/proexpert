<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class SensitiveDiagnosticSanitizer
{
    /** @var array<string, array{string, int}> */
    private const STRING_DOMAINS = [
        'provider_code' => ['/\A[a-z][a-z0-9._-]*\z/', 80],
        'http_class' => ['/\A[1-5]xx\z/', 3],
        'status' => ['/\A[a-z][a-z0-9_]*\z/', 40],
        'safe_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'validation_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'storage_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'claim_status' => ['/\A(?:lost|expired|stale|busy)\z/', 7],
        'lineage_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'failure_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
    ];

    /** @var array<string, array{int, int}> */
    private const INTEGER_DOMAINS = [
        'http_code' => [100, 599],
        'retry_after_seconds' => [0, 86400],
        'attempt' => [1, 1000],
    ];

    public function __construct(
        private int $maxDepth = 1,
        private int $maxItems = 24,
        private int $maxStringLength = 80,
    ) {
        if ($maxDepth !== 1 || $maxItems < 1 || $maxItems > 32 || $maxStringLength < 8 || $maxStringLength > 80) {
            throw new InvalidArgumentException('Invalid diagnostic sanitizer limits.');
        }
    }

    /** @param array<array-key, mixed> $context @return array<string, int|string> */
    public function sanitize(array $context): array
    {
        $result = [];
        foreach ($context as $rawKey => $value) {
            if (count($result) >= $this->maxItems || ! is_string($rawKey)) {
                break;
            }
            $key = strtolower($rawKey);
            if (isset(self::INTEGER_DOMAINS[$key])) {
                [$minimum, $maximum] = self::INTEGER_DOMAINS[$key];
                if (is_int($value) && $value >= $minimum && $value <= $maximum) {
                    $result[$key] = $value;
                }

                continue;
            }
            if (! isset(self::STRING_DOMAINS[$key]) || ! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                continue;
            }
            [$pattern, $maximumLength] = self::STRING_DOMAINS[$key];
            if (strlen($value) <= min($maximumLength, $this->maxStringLength)
                && preg_match($pattern, $value) === 1
                && ! $this->looksSecret($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function looksSecret(string $value): bool
    {
        return str_contains($value, '/')
            || str_contains($value, '\\')
            || preg_match('/(?:bearer|api[_-]?key|secret|password|token|eyj[a-z0-9_-]{8,}\.|akia[0-9a-z]{12,}|gh[pousr]_[0-9a-z]{12,}|sk-[0-9a-z]{8,})/i', $value) === 1
            || (strlen($value) >= 24 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1 && preg_match('/[A-Z]/', $value) === 1 && preg_match('/[a-z]/', $value) === 1 && preg_match('/[0-9]/', $value) === 1);
    }
}
