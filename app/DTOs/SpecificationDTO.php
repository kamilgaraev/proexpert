<?php

namespace App\DTOs;

class SpecificationDTO
{
    public function __construct(
        public readonly string $number,
        public readonly string $spec_date, // Y-m-d
        public readonly float $total_amount,
        public readonly array $scope_items,
        public readonly string $status = 'draft',
    ) {}

    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'spec_date' => $this->spec_date,
            'total_amount' => $this->total_amount,
            'scope_items' => $this->scope_items,
            'status' => $this->status,
        ];
    }
} 