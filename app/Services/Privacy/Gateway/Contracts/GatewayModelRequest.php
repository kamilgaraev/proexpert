<?php

declare(strict_types=1);

namespace App\Services\Privacy\Gateway\Contracts;

use LogicException;

final readonly class GatewayModelRequest
{
    public const SCHEMA_VERSION = 'public-core-model-request/1';

    public const CONTRACT_VERSION = 'public-core-gateway/0.2-candidate';

    public const PURPOSE = 'assistant_public_core_test';

    public const KEYS = [
        'schemaVersion', 'contractVersion', 'requestRef', 'attemptRef',
        'publicAdmissionRef', 'contextReceiptRef', 'corePayloadDigest',
        'coreReceiptDigest', 'projectionRef', 'projectionDigest',
        'profileRef', 'profileFingerprint', 'purpose', 'expiresAt', 'bodyBytes',
    ];

    private function __construct(
        public string $requestRef,
        public string $attemptRef,
        public string $publicAdmissionRef,
        public string $contextReceiptRef,
        public string $corePayloadDigest,
        public string $coreReceiptDigest,
        public string $projectionRef,
        public string $projectionDigest,
        public string $profileRef,
        public string $profileFingerprint,
        public int $expiresAt,
        public string $bodyBytes,
    ) {}

    public static function fromArray(mixed $value): self
    {
        if (! self::hasExactKeys($value, self::KEYS)
            || $value['schemaVersion'] !== self::SCHEMA_VERSION
            || $value['contractVersion'] !== self::CONTRACT_VERSION
            || $value['purpose'] !== self::PURPOSE) {
            throw new LogicException('invalid_gateway_request');
        }
        foreach (['requestRef', 'attemptRef', 'publicAdmissionRef', 'contextReceiptRef', 'projectionRef', 'profileRef'] as $key) {
            if (! self::isReference($value[$key])) {
                throw new LogicException('invalid_gateway_request');
            }
        }
        foreach (['corePayloadDigest', 'coreReceiptDigest', 'projectionDigest', 'profileFingerprint'] as $key) {
            if (! self::isDigest($value[$key])) {
                throw new LogicException('invalid_gateway_request');
            }
        }
        if (! is_int($value['expiresAt']) || $value['expiresAt'] < 1
            || ! is_string($value['bodyBytes']) || $value['bodyBytes'] === ''
            || strlen($value['bodyBytes']) > 262144 || preg_match('//u', $value['bodyBytes']) !== 1
            || str_contains($value['bodyBytes'], "\0")
            || ! hash_equals($value['projectionDigest'], hash('sha256', $value['bodyBytes']))) {
            throw new LogicException('invalid_gateway_request');
        }

        return new self(
            $value['requestRef'], $value['attemptRef'], $value['publicAdmissionRef'],
            $value['contextReceiptRef'], $value['corePayloadDigest'], $value['coreReceiptDigest'],
            $value['projectionRef'], $value['projectionDigest'], $value['profileRef'],
            $value['profileFingerprint'], $value['expiresAt'], $value['bodyBytes'],
        );
    }

    public function values(): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'contractVersion' => self::CONTRACT_VERSION,
            'requestRef' => $this->requestRef,
            'attemptRef' => $this->attemptRef,
            'publicAdmissionRef' => $this->publicAdmissionRef,
            'contextReceiptRef' => $this->contextReceiptRef,
            'corePayloadDigest' => $this->corePayloadDigest,
            'coreReceiptDigest' => $this->coreReceiptDigest,
            'projectionRef' => $this->projectionRef,
            'projectionDigest' => $this->projectionDigest,
            'profileRef' => $this->profileRef,
            'profileFingerprint' => $this->profileFingerprint,
            'purpose' => self::PURPOSE,
            'expiresAt' => $this->expiresAt,
            'bodyBytes' => $this->bodyBytes,
        ];
    }

    public function binding(): array
    {
        $value = $this->values();
        unset($value['schemaVersion'], $value['contractVersion'], $value['bodyBytes']);

        return $value;
    }

    public static function hasExactKeys(mixed $value, array $keys): bool
    {
        return is_array($value) && count($value) === count($keys)
            && array_diff($keys, array_keys($value)) === [];
    }

    public static function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_.:-]{16,256}\z/D', $value) === 1;
    }

    public static function isDigest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }

    public static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $value[$key] = json_decode(self::canonicalJson($child), true, 64, JSON_THROW_ON_ERROR);
                }
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
