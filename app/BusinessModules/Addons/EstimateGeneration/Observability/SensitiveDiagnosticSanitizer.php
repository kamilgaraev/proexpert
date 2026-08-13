<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class SensitiveDiagnosticSanitizer
{
    /** @var array<string, array{string, int}> */
    private const STRING_DOMAINS = [
        'provider_code' => ['/\A[a-z][a-z0-9._-]*\z/', 80],
        'provider' => ['/\A[a-z][a-z0-9_]{0,39}\z/', 40],
        'provider_error_type' => ['/\A[a-zA-Z][a-zA-Z0-9._-]*\z/', 80],
        'provider_error_code' => ['/\A[a-zA-Z][a-zA-Z0-9._-]*\z/', 80],
        'provider_error_param' => ['/\A[a-zA-Z][a-zA-Z0-9_.\[\]-]*\z/', 80],
        'endpoint_kind' => ['/\A[a-z][a-z0-9_]*\z/', 40],
        'body_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'body_shape_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'prompt_contract_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'payload_shape_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'http_class' => ['/\A[1-5]xx\z/', 3],
        'status' => ['/\A[a-z][a-z0-9_]*\z/', 40],
        'safe_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'validation_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'storage_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'claim_status' => ['/\A(?:lost|expired|stale|busy)\z/', 7],
        'lineage_code' => ['/\A[a-z][a-z0-9_]*\z/', 80],
        'failure_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'diagnostic_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'exception_chain_fingerprint' => ['/\Asha256:[0-9a-f]{64}\z/', 71],
        'exception_class' => ['/\A[a-z][a-z0-9_]{0,79}\z/', 80],
        'root_exception_class' => ['/\A[a-z][a-z0-9_]{0,79}\z/', 80],
        'execution_boundary' => ['/\A[a-z][a-z0-9_]{0,79}\z/', 80],
        'processing_attempt_id' => ['/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', 36],
        'requested_model' => ['/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}(?:\/[a-zA-Z0-9][a-zA-Z0-9._-]{0,79})?\z/', 80],
    ];

    /** @var array<string, array{int, int}> */
    private const INTEGER_DOMAINS = [
        'http_code' => [100, 599],
        'provider_http_status' => [100, 599],
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
                && ! $this->looksSecret($key, $value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function looksSecret(string $key, string $value): bool
    {
        return ($key !== 'requested_model' && str_contains($value, '/'))
            || str_contains($value, '\\')
            || preg_match('/(?:bearer|api[_-]?key|secret|password|token|eyj[a-z0-9_-]{8,}\.|akia[0-9a-z]{12,}|gh[pousr]_[0-9a-z]{12,}|sk-[0-9a-z]{8,})/i', $value) === 1
            || (strlen($value) >= 24 && preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1 && preg_match('/[A-Z]/', $value) === 1 && preg_match('/[a-z]/', $value) === 1 && preg_match('/[0-9]/', $value) === 1);
    }
}
