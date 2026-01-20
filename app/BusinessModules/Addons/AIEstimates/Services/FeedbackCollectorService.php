<?php

namespace App\BusinessModules\Addons\AIEstimates\Services;

use App\BusinessModules\Addons\AIEstimates\DTOs\FeedbackDTO;
use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationFeedback;
use App\BusinessModules\Addons\AIEstimates\Models\AIGenerationHistory;
use Illuminate\Support\Facades\Log;

class FeedbackCollectorService
{
    public function collectFeedback(int $generationId, FeedbackDTO $feedback): AIGenerationFeedback
    {
        try {
            $generation = AIGenerationHistory::findOrFail($generationId);

            // Рассчитываем acceptance rate
            $acceptanceRate = $feedback->getAcceptanceRate();

            $feedbackModel = AIGenerationFeedback::create([
                'generation_id' => $generationId,
                'feedback_type' => $feedback->type->value,
                'accepted_items' => $feedback->acceptedItems,
                'edited_items' => $feedback->editedItems,
                'rejected_items' => $feedback->rejectedItems,
                'user_comments' => $feedback->comments,
                'acceptance_rate' => $acceptanceRate,
                'used_for_training' => false,
            ]);

            Log::info('[FeedbackCollectorService] Feedback collected', [
                'generation_id' => $generationId,
                'feedback_type' => $feedback->type->value,
                'acceptance_rate' => $acceptanceRate,
            ]);

            return $feedbackModel;

        } catch (\Exception $e) {
            Log::error('[FeedbackCollectorService] Failed to collect feedback', [
                'generation_id' => $generationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getUnusedFeedback(int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return AIGenerationFeedback::unused()
            ->limit($limit)
            ->get();
    }

    public function markAsUsedForTraining(int $feedbackId): void
    {
        try {
            $feedback = AIGenerationFeedback::findOrFail($feedbackId);
            $feedback->markAsUsedForTraining();

            Log::info('[FeedbackCollectorService] Feedback marked as used for training', [
                'feedback_id' => $feedbackId,
            ]);
        } catch (\Exception $e) {
            Log::error('[FeedbackCollectorService] Failed to mark feedback as used', [
                'feedback_id' => $feedbackId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getAverageAcceptanceRate(int $organizationId): float
    {
        $feedbacks = AIGenerationFeedback::whereHas('generation', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->whereNotNull('acceptance_rate')
            ->get();

        if ($feedbacks->isEmpty()) {
            return 0.0;
        }

        return round($feedbacks->avg('acceptance_rate'), 2);
    }

    public function getFeedbackStats(int $organizationId): array
    {
        $feedbacks = AIGenerationFeedback::whereHas('generation', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->get();

        if ($feedbacks->isEmpty()) {
            return [
                'total' => 0,
                'positive' => 0,
                'negative' => 0,
                'average_acceptance_rate' => 0.0,
            ];
        }

        return [
            'total' => $feedbacks->count(),
            'positive' => $feedbacks->filter(fn($f) => $f->isPositive())->count(),
            'negative' => $feedbacks->filter(fn($f) => !$f->isPositive())->count(),
            'average_acceptance_rate' => round($feedbacks->avg('acceptance_rate'), 2),
        ];
    }
}
