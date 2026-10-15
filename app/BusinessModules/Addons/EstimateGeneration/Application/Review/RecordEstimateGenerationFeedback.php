<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationFeedback;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Learning\EstimateGenerationLearningRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateFeedbackService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecordEstimateGenerationFeedback
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private EstimateGenerationActionAuthorization $authorization,
        private NormativeCandidateFeedbackService $feedbackService,
        private EstimateGenerationLearningRecorder $learningRecorder,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(
        int $sessionId,
        int $organizationId,
        int $projectId,
        User $actor,
        int $expectedVersion,
        array $data,
    ): int {
        $requestId = $data['request_id'] ?? null;
        if ($requestId !== null && (! is_string($requestId) || ! Str::isUuid($requestId))) {
            throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.feedback_request_invalid')]);
        }
        $requestId = $requestId === null ? null : strtolower($requestId);
        $command = [
            'state_version' => $expectedVersion,
            'feedback_type' => $data['feedback_type'],
            'section_key' => $data['section_key'] ?? null,
            'work_item_key' => $data['work_item_key'] ?? null,
            'payload' => $data['payload'] ?? [],
            'comments' => $data['comments'] ?? null,
        ];
        $fingerprint = hash('sha256', CanonicalPipelineJson::encode($command));

        return DB::transaction(function () use ($sessionId, $organizationId, $projectId, $actor, $expectedVersion, $data, $requestId, $fingerprint): int {
            $session = EstimateGenerationSession::query()
                ->whereKey($sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->firstOrFail();
            // Authorization precedes replay: a receipt never grants access after revocation.
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $receipts = $session->input_payload['feedback_receipts'] ?? [];
            if ($requestId !== null && isset($receipts[$requestId])) {
                $receipt = $receipts[$requestId];
                if (($receipt['actor_id'] ?? null) !== (int) $actor->getKey()
                    || ! is_string($receipt['fingerprint'] ?? null)
                    || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.feedback_request_conflict')]);
                }

                return (int) $receipt['feedback_id'];
            }
            $this->policy->review($session, $expectedVersion);
            if ($requestId !== null && count($receipts) >= 10000) {
                throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.feedback_request_limit')]);
            }
            $feedback = EstimateGenerationFeedback::query()->create([
                'session_id' => $session->id,
                'user_id' => $actor->getKey(),
                'feedback_type' => $data['feedback_type'],
                'section_key' => $data['section_key'] ?? null,
                'work_item_key' => $data['work_item_key'] ?? null,
                'payload' => $data['payload'] ?? [],
                'comments' => $data['comments'] ?? null,
            ]);
            $this->feedbackService->apply($session, $feedback);
            $this->learningRecorder->recordFeedbackDecision($session, $feedback);
            if ($requestId !== null) {
                $receipts[$requestId] = ['actor_id' => (int) $actor->getKey(), 'fingerprint' => $fingerprint, 'feedback_id' => (int) $feedback->getKey()];
                $input = $session->input_payload ?? [];
                $input['feedback_receipts'] = $receipts;
                $session->forceFill(['input_payload' => $input])->save();
            }

            return (int) $feedback->getKey();
        });
    }
}
