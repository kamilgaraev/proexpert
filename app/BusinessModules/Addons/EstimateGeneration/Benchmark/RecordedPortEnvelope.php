<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use DateTimeImmutable;
use JsonException;

final readonly class RecordedPortEnvelope
{
    private const KEYS = [
        'schema_version', 'port', 'source_sha256', 'input_dependency_sha256', 'provider',
        'model_version', 'prompt_version', 'payload_schema_version', 'payload', 'payload_sha256',
        'privacy_scanner', 'privacy_scanner_version', 'capture_kind', 'approval_kind', 'approval_ref',
        'approved_at', 'manifest_sha256',
    ];

    private const FORBIDDEN_KEYS = [
        'expected', 'labels', 'metric', 'metrics', 'final_prediction', 'prediction',
        'readiness', 'price_total', 'prices_total', 'cost_total', 'total_price', 'total_cost',
        'password', 'passwd', 'secret', 'secret_key', 'access_token', 'refresh_token', 'api_key',
        'authorization', 'cookie', 'email', 'phone', 'client_id',
    ];

    public function __construct(
        public int $schemaVersion,
        public RecordedPort $port,
        public string $sourceSha256,
        public string $inputDependencySha256,
        public string $provider,
        public string $modelVersion,
        public string $promptVersion,
        public string $payloadSchemaVersion,
        public array $payload,
        public string $payloadSha256,
        public string $privacyScanner,
        public string $privacyScannerVersion,
        public string $approvalRef,
        public string $approvedAt,
        public string $manifestSha256,
    ) {}

    public static function fromFile(string $path): self
    {
        $size = @filesize($path);
        if (! is_int($size) || $size < 2 || $size > 2_000_000 || is_link($path)) {
            throw new RecordedPortEnvelopeException('recorded_envelope_file_invalid');
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RecordedPortEnvelopeException('recorded_envelope_json_invalid');
        }

        return is_array($data) ? self::fromArray($data) : throw new RecordedPortEnvelopeException('recorded_envelope_shape_invalid');
    }

    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $expected = self::KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($data['schema_version'] ?? null) !== 1
            || ($data['capture_kind'] ?? null) !== 'contract_fixture'
            || ($data['approval_kind'] ?? null) !== 'maintainer_code_review') {
            throw new RecordedPortEnvelopeException('recorded_envelope_contract_invalid');
        }
        $port = RecordedPort::tryFrom(is_string($data['port']) ? $data['port'] : '')
            ?? throw new RecordedPortEnvelopeException('recorded_port_invalid');
        foreach (['source_sha256', 'input_dependency_sha256', 'payload_sha256', 'manifest_sha256'] as $key) {
            if (! is_string($data[$key]) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                throw new RecordedPortEnvelopeException('recorded_hash_invalid');
            }
        }
        foreach (['provider', 'model_version', 'prompt_version', 'payload_schema_version', 'privacy_scanner', 'privacy_scanner_version', 'approval_ref'] as $key) {
            if (! is_string($data[$key]) || trim($data[$key]) !== $data[$key] || strlen($data[$key]) < 1 || strlen($data[$key]) > 160) {
                throw new RecordedPortEnvelopeException('recorded_metadata_invalid');
            }
        }
        if ($data['privacy_scanner'] !== 'most-fixture-privacy'
            || DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', (string) $data['approved_at']) === false
            || ! is_array($data['payload']) || ! array_is_list($data['payload']) && $data['payload'] === []) {
            throw new RecordedPortEnvelopeException('recorded_approval_invalid');
        }
        self::scan($data['payload'], 0, 0);
        $canonical = self::canonicalJson($data['payload']);
        if (strlen($canonical) > 1_000_000 || ! hash_equals($data['payload_sha256'], hash('sha256', $canonical))) {
            throw new RecordedPortEnvelopeException('recorded_payload_hash_mismatch');
        }

        return new self(
            1, $port, $data['source_sha256'], $data['input_dependency_sha256'], $data['provider'],
            $data['model_version'], $data['prompt_version'], $data['payload_schema_version'], $data['payload'],
            $data['payload_sha256'], $data['privacy_scanner'], $data['privacy_scanner_version'],
            $data['approval_ref'], $data['approved_at'], $data['manifest_sha256'],
        );
    }

    private static function scan(array $value, int $depth, int $nodes): int
    {
        if ($depth > 20 || $nodes > 20_000) {
            throw new RecordedPortEnvelopeException('recorded_payload_limit_exceeded');
        }
        foreach ($value as $key => $item) {
            $nodes++;
            if (is_string($key)) {
                $normalized = strtolower($key);
                if (in_array($normalized, self::FORBIDDEN_KEYS, true)
                    || str_starts_with($normalized, 'expected_')
                    || str_ends_with($normalized, '_secret')
                    || str_ends_with($normalized, '_token')
                    || str_ends_with($normalized, '_password')) {
                    throw new RecordedPortEnvelopeException('recorded_payload_forbidden_key');
                }
            }
            if (is_array($item)) {
                $nodes = self::scan($item, $depth + 1, $nodes);
            } elseif (! is_string($item) && ! is_int($item) && ! is_float($item) && ! is_bool($item) && $item !== null) {
                throw new RecordedPortEnvelopeException('recorded_payload_value_invalid');
            }
        }

        return $nodes;
    }

    private static function canonicalJson(array $value): string
    {
        self::sortRecursive($value);

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
