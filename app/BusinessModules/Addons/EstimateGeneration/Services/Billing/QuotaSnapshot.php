<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Billing;

final readonly class QuotaSnapshot
{
    public function __construct(
        public int $included,
        public ?int $purchased,
        public int $used,
        public ?int $available,
        public ?string $reservationStatus,
    ) {}

    /** @return array{included: int, purchased: int|null, used: int, available: int|null, reservation_status: string|null} */
    public function toArray(): array
    {
        return [
            'included' => $this->included,
            'purchased' => $this->purchased,
            'used' => $this->used,
            'available' => $this->available,
            'reservation_status' => $this->reservationStatus,
        ];
    }
}
