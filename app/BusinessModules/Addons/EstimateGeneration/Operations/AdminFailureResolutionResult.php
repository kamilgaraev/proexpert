<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Operations;

final readonly class AdminFailureResolutionResult
{
    private const REPLAY_MESSAGES = [
        'estimate_generation.failure_resolved' => true,
        'estimate_generation.admin_operation_not_found' => false,
        'estimate_generation.failure_resolution_state_conflict' => false,
    ];

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
        $keys = array_keys($payload);
        sort($keys);

        if ($keys !== ['message_key', 'successful']
            || ! is_bool($payload['successful'])
            || ! is_string($payload['message_key'])
            || strlen($payload['message_key']) > 80
            || ! array_key_exists($payload['message_key'], self::REPLAY_MESSAGES)
            || self::REPLAY_MESSAGES[$payload['message_key']] !== $payload['successful']) {
            return self::failure('estimate_generation.failure_resolution_failed');
        }

        return new self($payload['successful'], $payload['message_key'], true);
    }
}
