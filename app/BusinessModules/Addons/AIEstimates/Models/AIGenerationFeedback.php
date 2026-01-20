<?php

namespace App\BusinessModules\Addons\AIEstimates\Models;

use App\BusinessModules\Addons\AIEstimates\Enums\FeedbackType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIGenerationFeedback extends Model
{
    use HasFactory;

    protected $table = 'ai_generation_feedback';

    protected $fillable = [
        'generation_id',
        'feedback_type',
        'accepted_items',
        'edited_items',
        'rejected_items',
        'user_comments',
        'used_for_training',
        'acceptance_rate',
    ];

    protected $casts = [
        'feedback_type' => FeedbackType::class,
        'accepted_items' => 'array',
        'edited_items' => 'array',
        'rejected_items' => 'array',
        'used_for_training' => 'boolean',
        'acceptance_rate' => 'decimal:2',
    ];

    // Relationships

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AIGenerationHistory::class, 'generation_id');
    }

    // Scopes

    public function scopePositive($query)
    {
        return $query->whereIn('feedback_type', [
            FeedbackType::ACCEPTED->value,
            FeedbackType::PARTIALLY_ACCEPTED->value,
        ]);
    }

    public function scopeNegative($query)
    {
        return $query->where('feedback_type', FeedbackType::REJECTED->value);
    }

    public function scopeUnused($query)
    {
        return $query->where('used_for_training', false);
    }

    // Helpers

    public function isPositive(): bool
    {
        return $this->feedback_type->isPositive();
    }

    public function getTotalItemsCount(): int
    {
        return count($this->accepted_items ?? []) +
               count($this->edited_items ?? []) +
               count($this->rejected_items ?? []);
    }

    public function getAcceptedCount(): int
    {
        return count($this->accepted_items ?? []);
    }

    public function getEditedCount(): int
    {
        return count($this->edited_items ?? []);
    }

    public function getRejectedCount(): int
    {
        return count($this->rejected_items ?? []);
    }

    public function calculateAcceptanceRate(): float
    {
        $total = $this->getTotalItemsCount();
        
        if ($total === 0) {
            return 0.0;
        }

        $accepted = $this->getAcceptedCount();
        return round(($accepted / $total) * 100, 2);
    }

    public function markAsUsedForTraining(): void
    {
        $this->update(['used_for_training' => true]);
    }
}
