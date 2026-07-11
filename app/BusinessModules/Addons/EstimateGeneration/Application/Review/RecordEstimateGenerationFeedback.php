<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService;
use Illuminate\Support\Facades\DB;

final class RecordEstimateGenerationFeedback
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private NormativeCandidateFeedbackService $feedbackService,
        private EstimateGenerationLearningRecorder $learningRecorder,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(
        int $sessionId,
        int $organizationId,
        int $projectId,
        int $userId,
        int $expectedVersion,
        array $data,
    ): int {
        return DB::transaction(function () use ($sessionId, $organizationId, $projectId, $userId, $expectedVersion, $data): int {
            $session = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->policy->review($session, $expectedVersion);
            $feedback = EstimateGenerationFeedback::query()->create([
                'session_id' => $session->id,
                'user_id' => $userId,
                'feedback_type' => $data['feedback_type'],
                'section_key' => $data['section_key'] ?? null,
                'work_item_key' => $data['work_item_key'] ?? null,
                'payload' => $data['payload'] ?? [],
                'comments' => $data['comments'] ?? null,
            ]);
            $this->feedbackService->apply($session, $feedback);
            $this->learningRecorder->recordFeedbackDecision($session, $feedback);

            return (int) $feedback->getKey();
        });
    }
}
