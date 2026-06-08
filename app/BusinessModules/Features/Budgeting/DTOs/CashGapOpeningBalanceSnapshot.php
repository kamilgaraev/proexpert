<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class CashGapOpeningBalanceSnapshot
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public string $balanceDate,
        public string $currency,
        public float $amount,
        public string $status,
        public ?int $approvedByUserId = null,
        public ?string $approvedAt = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId,
            'balance_date' => $this->balanceDate,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'status' => $this->status,
            'approved_by_user_id' => $this->approvedByUserId,
            'approved_at' => $this->approvedAt,
            'source_kind' => 'management_opening_balance',
        ];
    }
}
