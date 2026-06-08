<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class CashGapScenarioAdjustment
{
    public const ACTION_RESCHEDULE_PAYMENT = 'reschedule_payment';
    public const ACTION_CHANGE_INFLOW_PROBABILITY = 'change_inflow_probability';
    public const ACTION_EXCLUDE_PAYMENT = 'exclude_payment';
    public const ACTION_ADD_TEMPORARY_INFLOW = 'add_temporary_inflow';
    public const ACTION_ADD_TEMPORARY_FINANCING = 'add_temporary_financing';

    public function __construct(
        public string $action,
        public ?string $cashFlowKey = null,
        public ?string $sourceType = null,
        public int|string|null $sourceId = null,
        public ?string $date = null,
        public ?float $probability = null,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $description = null,
        public ?string $reason = null,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            action: (string) ($payload['action'] ?? ''),
            cashFlowKey: self::nullableString($payload['cash_flow_key'] ?? null),
            sourceType: self::nullableString($payload['source_type'] ?? null),
            sourceId: $payload['source_id'] ?? null,
            date: self::nullableString($payload['date'] ?? null),
            probability: array_key_exists('probability', $payload) ? (float) $payload['probability'] : null,
            amount: array_key_exists('amount', $payload) ? (float) $payload['amount'] : null,
            currency: self::nullableString($payload['currency'] ?? null),
            description: self::nullableString($payload['description'] ?? null),
            reason: self::nullableString($payload['reason'] ?? null),
        );
    }

    public function targets(CashGapForecastItem $item): bool
    {
        if ($this->cashFlowKey !== null && $item->normalizedCashFlowKey() === $this->cashFlowKey) {
            return true;
        }

        if ($this->sourceType === null || $this->sourceId === null) {
            return false;
        }

        return $item->sourceType === $this->sourceType
            && (string) $item->sourceId === (string) $this->sourceId;
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'cash_flow_key' => $this->cashFlowKey,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'date' => $this->date,
            'probability' => $this->probability,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'reason' => $this->reason,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
