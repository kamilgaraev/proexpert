<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminSessionOperationResult
{
    private function __construct(
        public bool $successful,
        public string $messageKey,
        public ?string $status,
        public ?int $stateVersion,
        public bool $idempotentReplay,
    ) {}

    public static function success(
        string $messageKey,
        string $status,
        int $stateVersion,
        bool $idempotentReplay = false,
    ): self {
        return new self(true, $messageKey, $status, $stateVersion, $idempotentReplay);
    }

    public static function failure(string $messageKey): self
    {
        return new self(false, $messageKey, null, null, false);
    }

    /** @return array{successful: bool, message_key: string, status: ?string, state_version: ?int, idempotent_replay: bool} */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'message_key' => $this->messageKey,
            'status' => $this->status,
            'state_version' => $this->stateVersion,
            'idempotent_replay' => $this->idempotentReplay,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload, bool $idempotentReplay = false): self
    {
        if (($payload['successful'] ?? false) !== true) {
            return self::failure((string) ($payload['message_key'] ?? 'estimate_generation.admin_operation_failed'));
        }

        return self::success(
            (string) ($payload['message_key'] ?? 'estimate_generation.admin_operation_completed'),
            (string) ($payload['status'] ?? ''),
            (int) ($payload['state_version'] ?? 0),
            $idempotentReplay,
        );
    }
}
