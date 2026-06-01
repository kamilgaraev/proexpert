<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

final readonly class ProcurementChainDocumentLink
{
    public function __construct(
        public string $type,
        public int $id,
        public string $label,
        public ?string $number,
        public ?string $status,
        public ?string $statusLabel,
        public string $href,
        public ?float $amount = null,
        public ?string $currency = null,
        public ?string $supplierName = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'label' => $this->label,
            'number' => $this->number,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'href' => $this->href,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'supplier_name' => $this->supplierName,
        ];
    }
}
