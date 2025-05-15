<?php

namespace App\DTOs\Contract; // Помещаем в общую папку DTO для контрактов

class ContractPerformanceActDTO
{
    public function __construct(
        // contract_id будет браться из маршрута или устанавливаться в сервисе
        public readonly ?string $act_document_number,
        public readonly string $act_date, // Y-m-d format
        public readonly float $amount,
        public readonly ?string $description,
        public readonly bool $is_approved = true, // По умолчанию одобрен при создании, если не указано иное
        public readonly ?string $approval_date // Y-m-d format, если is_approved = true
    ) {}

    public function toArray(): array
    {
        return [
            'act_document_number' => $this->act_document_number,
            'act_date' => $this->act_date,
            'amount' => $this->amount,
            'description' => $this->description,
            'is_approved' => $this->is_approved,
            'approval_date' => $this->is_approved ? ($this->approval_date ?? now()->toDateString()) : null,
        ];
    }
} 