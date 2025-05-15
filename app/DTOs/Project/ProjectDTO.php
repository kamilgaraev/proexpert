<?php

namespace App\DTOs\Project;

class ProjectDTO
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $description,
        public readonly ?string $customer,
        public readonly ?string $designer,
        public readonly ?string $start_date,
        public readonly ?string $end_date,
        public readonly string $status,
        public readonly ?bool $is_archived,
        public readonly ?array $additional_info,
        public readonly ?string $external_code,
        public readonly ?int $cost_category_id,
        public readonly ?array $accounting_data,
        public readonly ?bool $use_in_accounting_reports
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'description' => $this->description,
            'customer' => $this->customer,
            'designer' => $this->designer,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'is_archived' => $this->is_archived,
            'additional_info' => $this->additional_info,
            'external_code' => $this->external_code,
            'cost_category_id' => $this->cost_category_id,
            'accounting_data' => $this->accounting_data,
            'use_in_accounting_reports' => $this->use_in_accounting_reports,
        ];
    }
} 