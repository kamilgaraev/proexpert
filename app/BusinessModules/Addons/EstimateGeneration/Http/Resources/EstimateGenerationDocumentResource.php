<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentLifecycleState;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSystemFailureDetector;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentActionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

/**
 * @mixin EstimateGenerationDocument
 */
class EstimateGenerationDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var EstimateGenerationDocument $document */
        $document = $this->resource;
        $user = $request->user();

        return [
            'id' => $document->id,
            'filename' => $document->filename,
            'mime_type' => $document->mime_type,
            'status' => $document->status ?? 'uploaded',
            'processing_stage' => $document->processing_stage ?? 'stored',
            'lifecycle' => DocumentLifecycleState::forDocument($document),
            'progress_percent' => (int) ($document->progress_percent ?? 0),
            'page_count' => $document->page_count,
            'processed_page_count' => (int) ($document->processed_page_count ?? 0),
            'processing_outcome' => $this->processingOutcome($document),
            'quality' => [
                'score' => $document->quality_score,
                'level' => $document->quality_level,
                'flags' => $document->quality_flags ?? [],
            ],
            'document_understanding' => $this->documentUnderstanding($document),
            'analysis_status' => $this->analysisStatus($document),
            'understanding_summary' => [
                'pages' => $this->countRelationOrAttribute($document, 'pages_count', 'pages'),
                'facts' => $this->countRelationOrAttribute($document, 'facts_count', 'facts'),
                'drawing_elements' => $this->countRelationOrAttribute($document, 'drawing_elements_count', 'drawingElements'),
                'quantity_takeoffs' => $this->countRelationOrAttribute($document, 'quantity_takeoffs_count', 'quantityTakeoffs'),
                'scope_inferences' => $this->countRelationOrAttribute($document, 'scope_inferences_count', 'scopeInferences'),
            ],
            'error' => $this->errorPayload($document),
            'meta' => $document->meta ?? [],
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
            'state_version' => $document->relationLoaded('session') ? (int) $document->session?->state_version : null,
            'available_actions' => $user instanceof User
                ? app(EstimateGenerationDocumentActionBuilder::class)->forDocument($document, $user)
                : [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function errorPayload(EstimateGenerationDocument $document): ?array
    {
        if ($document->error_code === null && $document->error_message_key === null) {
            return null;
        }

        return [
            'code' => $document->error_code,
            'message_key' => $document->error_message_key,
            'message' => $document->error_message_key !== null
                ? trans_message($document->error_message_key)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function processingOutcome(EstimateGenerationDocument $document): array
    {
        $facts = is_array($document->facts_summary) ? $document->facts_summary : [];
        $stored = is_array($facts['processing_outcome'] ?? null) ? $facts['processing_outcome'] : [];
        $failureDetector = app(DocumentSystemFailureDetector::class);
        $legacyTemporaryFailure = $stored === [] && $failureDetector->temporary($document);
        $legacySystemFailure = $stored === [] && ! $legacyTemporaryFailure && $failureDetector->detected($document);
        $type = $legacyTemporaryFailure
            ? 'temporary_failure'
            : ($legacySystemFailure
            ? 'system_failure'
            : (is_string($stored['type'] ?? null) ? $stored['type'] : match ((string) $document->status) {
                'ready' => 'ready',
                'failed' => 'user_action_required',
                'needs_review' => 'user_action_required',
                default => 'processing',
            }));
        $counts = is_array($stored['counts'] ?? null) ? $stored['counts'] : [];
        $included = max(0, (int) ($counts['included'] ?? $document->page_count ?? 0));
        $ready = max(0, (int) ($counts['ready'] ?? $document->processed_page_count ?? 0));
        $counts = [
            'included' => $included,
            'ready' => $ready,
            'needs_user_action' => max(0, (int) ($counts['needs_user_action'] ?? ($type === 'user_action_required' ? $included - $ready : 0))),
            'terminal_system_failed' => max(0, (int) ($counts['terminal_system_failed'] ?? $counts['system_failed'] ?? (in_array($type, ['system_failure', 'temporary_failure'], true) ? $included - $ready : 0))),
            'breaker_stopped' => max(0, (int) ($counts['breaker_stopped'] ?? 0)),
            'system_failed' => max(0, (int) ($counts['system_failed'] ?? (in_array($type, ['system_failure', 'temporary_failure'], true) ? $included - $ready : 0))),
            'processing' => max(0, (int) ($counts['processing'] ?? ($type === 'processing' ? $included - $ready : 0))),
            'excluded' => max(0, (int) ($counts['excluded'] ?? 0)),
        ];
        $messageKey = match ($type) {
            'ready' => 'estimate_generation.document_processing_ready',
            'temporary_failure' => 'estimate_generation.document_processing_temporarily_unavailable',
            'system_failure' => 'estimate_generation.document_processing_system_failed',
            'user_action_required' => 'estimate_generation.document_processing_user_action_required',
            default => 'estimate_generation.document_processing_in_progress',
        };
        $state = is_string($stored['state'] ?? null) ? $stored['state'] : match (true) {
            $type === 'processing' => 'processing',
            $counts['ready'] > 0 && $counts['system_failed'] > 0 => 'partial',
            $counts['needs_user_action'] > 0 => 'questions',
            in_array($type, ['system_failure', 'temporary_failure'], true) => 'system_failure',
            default => 'ready',
        };

        return [
            'type' => $type,
            'state' => $state,
            'counts' => $counts,
            'retry_allowed' => ($stored['retry_allowed'] ?? false) === true,
            'execution_progress_percent' => max(0, min(100, (int) ($stored['execution_progress_percent'] ?? $document->progress_percent ?? 0))),
            'readiness' => is_string($stored['readiness'] ?? null) ? $stored['readiness'] : match ($type) {
                'ready' => 'ready',
                'processing' => 'processing',
                'user_action_required' => 'review_required',
                default => 'blocked',
            },
            'is_ready' => ($stored['is_ready'] ?? null) === true || $type === 'ready',
            'message_key' => $messageKey,
            'message' => trans_message($messageKey),
        ];
    }

    protected function countRelationOrAttribute(EstimateGenerationDocument $document, string $attribute, string $relation): int
    {
        if ($document->relationLoaded($relation)) {
            return $document->{$relation}->count();
        }

        $value = $document->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function documentUnderstanding(EstimateGenerationDocument $document): ?array
    {
        $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
        $meta = is_array($document->meta) ? $document->meta : [];
        $understanding = $factsSummary['document_understanding'] ?? $meta['document_understanding'] ?? null;

        return is_array($understanding) ? $understanding : null;
    }

    /** @return array{roles_complete: bool, question_count: int} */
    private function analysisStatus(EstimateGenerationDocument $document): array
    {
        $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];

        return [
            'roles_complete' => ($factsSummary['analysis_roles_complete'] ?? false) === true,
            'question_count' => max(0, (int) ($factsSummary['ai_question_count'] ?? 0)),
        ];
    }
}
