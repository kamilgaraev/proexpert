<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminFailureResolutionResult
{
    private function __construct(
        public bool $successful,
        public string $messageKey,
        public bool $idempotentReplay,
    ) {}

    public static function success(bool $idempotentReplay = false): self
    {
        return new self(true, 'estimate_generation.failure_resolved', $idempotentReplay);
    }

    public static function failure(string $messageKey): self
    {
        return new self(false, $messageKey, false);
    }

    /** @return array{successful: bool, message_key: string} */
    public function toArray(): array
    {
        return ['successful' => $this->successful, 'message_key' => $this->messageKey];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        if (($payload['successful'] ?? false) !== true) {
            return self::failure((string) ($payload['message_key'] ?? 'estimate_generation.failure_resolution_failed'));
        }

        return self::success(true);
    }
}
