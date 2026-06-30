<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

/**
 * @mixin EstimateGenerationSession
 */
class EstimateGenerationSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var EstimateGenerationSession $session */
        $session = $this->resource;
        $input = $session->input_payload ?? [];
        $analysis = $session->analysis_payload ?? [];
        $draft = $session->draft_payload ?? [];
        $regionalContext = $input['regional_context'] ?? $analysis['regional_context'] ?? null;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'processing_stage' => $session->processing_stage,
            'processing_progress' => $session->processing_progress,
            'progress' => self::progressPayload($session),
            'generation_mode' => $input['generation_mode'] ?? $draft['generation_mode'] ?? 'strict_documents',
            'document_requirements' => $draft['document_requirements'] ?? null,
            'input' => $input,
            'analysis' => $analysis,
            'regional_context' => $regionalContext,
            'estimator_readiness' => app(EstimatorReadinessService::class)->evaluate($session),
            'problem_flags' => $session->problem_flags ?? [],
            'applied_estimate_id' => $session->applied_estimate_id,
            'last_error' => $session->last_error,
            'documents' => $this->whenLoaded('documents', function (): array {
                return EstimateGenerationDocumentResource::collection($this->documents)->resolve();
            }, []),
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function progressPayload(EstimateGenerationSession $session): array
    {
        $stage = self::normalizedStage($session);
        $percent = (int) ($session->processing_progress ?? 0);

        if ($stage === 'ready_for_review') {
            $percent = 100;
        }

        if ($stage === 'failed') {
            $percent = 0;
        }

        return [
            'stage' => $stage,
            'percent' => max(0, min($percent, 100)),
            'title' => trans_message('estimate_generation.stage_' . $stage),
            'description' => trans_message('estimate_generation.stage_' . $stage . '_description'),
            'can_close_page' => self::canClosePage($session),
            'user_action' => self::userAction($session),
        ];
    }

    private static function normalizedStage(EstimateGenerationSession $session): string
    {
        $status = (string) $session->status;
        $stage = (string) ($session->processing_stage ?? '');

        if ($status === 'failed') {
            return 'failed';
        }

        if ($status === 'blocked') {
            return 'blocked';
        }

        if (in_array($status, ['ready_for_review', 'review_required'], true)) {
            return 'ready_for_review';
        }

        if ($status === 'waiting_for_documents') {
            return 'documents_processing';
        }

        return match ($stage) {
            'documents_processing' => 'documents_processing',
            'object_understanding', 'object_analysis', 'analyzed' => 'object_analysis',
            'queued', 'draft_generation', 'package_planning' => 'package_planning',
            'work_generation' => 'work_generation',
            'normative_matching' => 'normative_matching',
            'resource_enrichment' => 'resource_enrichment',
            'validation_and_normalization' => 'validation_and_normalization',
            default => $status === 'created' ? 'object_analysis' : 'package_planning',
        };
    }

    private static function canClosePage(EstimateGenerationSession $session): bool
    {
        return !in_array((string) $session->status, ['failed'], true);
    }

    private static function userAction(EstimateGenerationSession $session): string
    {
        return match ((string) $session->status) {
            'failed' => 'retry',
            'blocked', 'review_required', 'ready_for_review' => 'review',
            default => 'wait',
        };
    }
}
