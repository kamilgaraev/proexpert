<?php

namespace App\BusinessModules\Addons\AIEstimates\DTOs;

use App\BusinessModules\Addons\AIEstimates\Enums\FeedbackType;

class FeedbackDTO
{
    public function __construct(
        public readonly FeedbackType $type,
        public readonly ?array $acceptedItems = null,
        public readonly ?array $editedItems = null,
        public readonly ?array $rejectedItems = null,
        public readonly ?string $comments = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            type: FeedbackType::from($data['feedback_type']),
            acceptedItems: $data['accepted_items'] ?? null,
            editedItems: $data['edited_items'] ?? null,
            rejectedItems: $data['rejected_items'] ?? null,
            comments: $data['comments'] ?? null,
        );
    }

    public function getAcceptanceRate(): float
    {
        $totalItems = count($this->acceptedItems ?? []) + 
                     count($this->editedItems ?? []) + 
                     count($this->rejectedItems ?? []);

        if ($totalItems === 0) {
            return 0.0;
        }

        $acceptedCount = count($this->acceptedItems ?? []);
        return round(($acceptedCount / $totalItems) * 100, 2);
    }

    public function toArray(): array
    {
        return [
            'feedback_type' => $this->type->value,
            'accepted_items' => $this->acceptedItems,
            'edited_items' => $this->editedItems,
            'rejected_items' => $this->rejectedItems,
            'comments' => $this->comments,
            'acceptance_rate' => $this->getAcceptanceRate(),
        ];
    }
}
