<?php

namespace App\DTOs\CompletedWork;

use Carbon\Carbon;

class CompletedWorkDTO
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $organization_id,
        public readonly int $project_id,
        public readonly ?int $contract_id,
        public readonly int $work_type_id,
        public readonly int $user_id,
        public readonly float $quantity,
        public readonly ?float $price,
        public readonly ?float $total_amount,
        public readonly Carbon|string $completion_date,
        public readonly ?string $notes,
        public readonly string $status,
        public readonly ?array $additional_info
    ) {}

    public static function fromModel(object $model): self
    {
        return new self(
            id: $model->id,
            organization_id: $model->organization_id,
            project_id: $model->project_id,
            contract_id: $model->contract_id,
            work_type_id: $model->work_type_id,
            user_id: $model->user_id,
            quantity: (float)$model->quantity,
            price: isset($model->price) ? (float)$model->price : null,
            total_amount: isset($model->total_amount) ? (float)$model->total_amount : null,
            completion_date: $model->completion_date instanceof Carbon ? $model->completion_date : Carbon::parse($model->completion_date),
            notes: $model->notes,
            status: $model->status,
            additional_info: $model->additional_info
        );
    }

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'contract_id' => $this->contract_id,
            'work_type_id' => $this->work_type_id,
            'user_id' => $this->user_id,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'total_amount' => $this->total_amount,
            'completion_date' => $this->completion_date instanceof Carbon ? $this->completion_date->toDateString() : Carbon::parse($this->completion_date)->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'additional_info' => $this->additional_info,
        ];
    }
} 