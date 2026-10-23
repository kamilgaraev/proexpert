<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\DTOs;

use App\BusinessModules\Features\BasicWarehouse\Enums\WarehouseReceiptVatMode;

final readonly class WarehouseReceiptTaxSnapshot
{
    public function __construct(
        public WarehouseReceiptVatMode $mode,
        public string $quantity,
        public string $unitPrice,
        public ?string $rate,
        public string $netAmount,
        public string $vatAmount,
        public string $grossAmount,
    ) {}

    public function toArray(): array
    {
        return [
            'version' => 1,
            'mode' => $this->mode->value,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice,
            'rate' => $this->rate,
            'net_amount' => $this->netAmount,
            'vat_amount' => $this->vatAmount,
            'gross_amount' => $this->grossAmount,
        ];
    }
}
