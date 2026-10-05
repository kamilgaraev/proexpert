<?php

declare(strict_types=1);

namespace App\Services\Privacy\Contracts;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;

final readonly class PrivacyDecision implements JsonSerializable
{
    private function __construct(
        private string $status,
        private string $reason,
        private object|string|null $value,
        private string $correlationRef,
    ) {
    }

    public static function ready(object|string $value): self
    {
        return new self('ready', 'none', $value, 'ref_' . bin2hex(random_bytes(16)));
    }

    public static function blocked(string $reason = 'dependency_unavailable'): self
    {
        return self::refusal('blocked', $reason);
    }

    public static function stale(): self
    {
        return self::refusal('stale', 'source_stale');
    }

    public static function unsupported(): self
    {
        return self::refusal('unsupported', 'unsupported_purpose');
    }

    private static function refusal(string $status, string $reason): self
    {
        if (!in_array($reason, [
            'dependency_unavailable', 'auth_missing', 'access_denied', 'scope_mismatch',
            'source_stale', 'source_unknown', 'untrusted_creator', 'unknown_category',
            'required_stage_unavailable', 'fixture_mismatch', 'unsupported_purpose',
        ], true)) {
            throw new InvalidArgumentException('unsupported_privacy_reason');
        }

        return new self($status, $reason, null, 'ref_' . bin2hex(random_bytes(16)));
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function value(): object|string|null
    {
        return $this->value;
    }

    public function jsonSerialize(): array
    {
        return [
            'schemaVersion' => 'privacy-decision/1',
            'status' => $this->status,
            'reason' => $this->reason,
            'correlationRef' => $this->correlationRef,
        ];
    }

    public function __serialize(): array
    {
        throw new LogicException('privacy_decision_serialization_forbidden');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('privacy_decision_deserialization_forbidden');
    }
}
