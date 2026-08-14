<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ManageEstimateGenerationDocumentPages;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentPreviewService;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ClarificationQuestionProjector;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @mixin EstimateGenerationDocument
 */
class EstimateGenerationDocumentDetailResource extends EstimateGenerationDocumentResource
{
    public function toArray(Request $request): array
    {
        /** @var EstimateGenerationDocument $document */
        $document = $this->resource;
        $payload = parent::toArray($request);
        $user = $request->user();
        $payload['preview_url'] = $user instanceof User
            ? app(EstimateGenerationDocumentPreviewService::class)->forDocument($document, $user)
            : null;

        $systemFailure = in_array($payload['processing_outcome']['type'] ?? null, ['system_failure', 'temporary_failure'], true);
        $payload['pages'] = $this->whenLoaded('pages', function () use ($document, $systemFailure, $payload): array {
            $units = $document->relationLoaded('processingUnits')
                ? $document->processingUnits->keyBy(static fn ($unit): int => (int) $unit->id)
                : collect();
            $terminal = ($payload['processing_outcome']['counts']['processing'] ?? 0) === 0
                && (string) $document->processing_stage === 'completed';

            return $document->pages->map(static fn ($page): array => [
                'id' => $page->id,
                'processing_unit_id' => $page->processing_unit_id,
                'page_number' => $page->page_number,
                'width' => $page->width,
                'height' => $page->height,
                'rotation' => $page->rotation,
                'language_codes' => $page->language_codes ?? [],
                'text' => $page->text,
                'text_hash' => $page->text_hash,
                'status' => self::pageStatus($page, $units->get((int) $page->processing_unit_id), $terminal),
                'excluded' => (string) $page->status === ManageEstimateGenerationDocumentPages::STATUS_EXCLUDED,
                'excluded_at' => $page->excluded_at?->toISOString(),
                'excluded_reason' => $page->excluded_reason,
                'retry_attempt_id' => $page->retry_attempt_id,
                'last_retry_requested_at' => $page->last_retry_requested_at?->toISOString(),
                'page_role' => self::pageRole($page),
                'role_for_estimation' => self::roleForEstimation($page),
                'review' => $systemFailure ? ['required' => false, 'reasons' => []] : self::reviewPayload($page),
                'geometry' => self::geometryPayload($page),
                'visual_metrics' => self::visualMetrics($page),
                'overlay' => self::overlayPayload($page),
                'quality_flags' => $page->quality_flags ?? [],
                'semantic_analysis' => self::semanticAnalysis($page),
            ])->all();
        }, []);
        $payload['ai_questions'] = $document->relationLoaded('pages')
            ? array_map(
                static fn ($question): array => $question->toArray(),
                app(ClarificationQuestionProjector::class)->projectPages($document->pages->map(
                    static fn ($page): array => self::normalizedPayload($page),
                )->all()),
            )
            : [];

        $payload['processing_units'] = $this->whenLoaded('processingUnits', function () use ($document): array {
            return $document->processingUnits->map(static fn ($unit): array => [
                'id' => $unit->id,
                'unit_type' => $unit->unit_type->value,
                'unit_index' => $unit->unit_index,
                'status' => $unit->status->value,
                'attempt_count' => $unit->attempt_count,
                'actual_execution_count' => self::actualExecutionCount($unit),
                'output_count' => $unit->output_count,
                'failure_code' => $unit->failure_code,
            ])->all();
        }, []);

        $payload['facts'] = $this->whenLoaded('facts', function () use ($document): array {
            return $document->facts->map(static fn ($fact): array => [
                'id' => $fact->id,
                'page_id' => $fact->page_id,
                'fact_type' => $fact->fact_type,
                'scope_key' => $fact->scope_key,
                'label' => $fact->label,
                'value_text' => $fact->value_text,
                'value_number' => $fact->value_number,
                'unit' => $fact->unit,
                'source_ref' => $fact->source_ref ?? [],
            ])->all();
        }, []);

        $payload['drawing_elements'] = $this->whenLoaded('drawingElements', function () use ($document): array {
            return $document->drawingElements->map(static fn ($element): array => [
                'id' => $element->id,
                'page_id' => $element->page_id,
                'type' => $element->type,
                'label' => $element->label,
                'value_text' => $element->value_text,
                'value_number' => $element->value_number,
                'unit' => $element->unit,
                'bbox' => $element->bbox ?? [],
                'geometry' => $element->geometry ?? [],
                'source_ref' => $element->source_ref ?? [],
            ])->all();
        }, []);

        $payload['quantity_takeoffs'] = $this->whenLoaded('quantityTakeoffs', function () use ($document): array {
            return $document->quantityTakeoffs->map(static fn ($takeoff): array => [
                'id' => $takeoff->id,
                'page_id' => $takeoff->page_id,
                'source_element_ids' => $takeoff->source_element_ids ?? [],
                'scope_key' => $takeoff->scope_key,
                'work_intent' => $takeoff->work_intent ?? [],
                'name' => $takeoff->name,
                'unit' => $takeoff->unit,
                'quantity' => $takeoff->quantity,
                'formula' => $takeoff->formula,
                'source_refs' => $takeoff->source_refs ?? [],
            ])->all();
        }, []);

        $payload['scope_inferences'] = $this->whenLoaded('scopeInferences', function () use ($document): array {
            return $document->scopeInferences->map(static fn ($inference): array => [
                'id' => $inference->id,
                'page_id' => $inference->page_id,
                'inference_type' => $inference->inference_type,
                'title' => $inference->title,
                'description' => $inference->description,
                'source_refs' => $inference->source_refs ?? [],
                'normative_basis' => $inference->normative_basis ?? [],
                'work_intent' => $inference->work_intent ?? [],
                'review_required' => $inference->review_required,
                'accepted_at' => $inference->accepted_at?->toISOString(),
            ])->all();
        }, []);

        return $payload;
    }

    private static function pageStatus(mixed $page, mixed $unit = null, bool $terminal = false): string
    {
        $unitStatus = $unit?->status?->value;
        if ($unitStatus === 'failed') {
            return ManageEstimateGenerationDocumentPages::STATUS_FAILED;
        }
        if ($unitStatus === 'completed' && (int) $unit->output_count > 0) {
            return (string) $page->status === ManageEstimateGenerationDocumentPages::STATUS_NEEDS_REVIEW
                ? ManageEstimateGenerationDocumentPages::STATUS_NEEDS_REVIEW
                : ManageEstimateGenerationDocumentPages::STATUS_READY;
        }
        if ($terminal && in_array($unitStatus, ['pending', 'running'], true)) {
            return ManageEstimateGenerationDocumentPages::STATUS_FAILED;
        }
        if (is_string($page->status) && $page->status !== '') {
            return $page->status;
        }

        return $page->output_version !== null || $page->text !== null
            ? ManageEstimateGenerationDocumentPages::STATUS_READY
            : ManageEstimateGenerationDocumentPages::STATUS_QUEUED;
    }

    /** @return array<string, mixed> */
    private static function semanticAnalysis(mixed $page): array
    {
        $payload = self::normalizedPayload($page);
        $vision = is_array($payload['vision_analysis'] ?? null) ? $payload['vision_analysis'] : [];
        $elements = is_array($vision['elements'] ?? null) ? array_values(array_filter($vision['elements'], 'is_array')) : [];
        $completion = is_array($payload['role_completion'] ?? null) ? $payload['role_completion'] : [];
        $arbitration = is_array($payload['document_arbitration'] ?? null) ? $payload['document_arbitration'] : [];
        $decisions = is_array($arbitration['decisions'] ?? null) ? $arbitration['decisions'] : [];
        $facts = [];
        foreach ($decisions as $decision) {
            $canonical = is_array($decision) ? ($decision['canonical_claim'] ?? null) : null;
            if (! is_array($canonical) || ! in_array($decision['status'] ?? null, ['accepted', 'candidate'], true)) {
                continue;
            }
            $facts[] = [
                'entityKey' => $canonical['entity_key'] ?? null,
                'factType' => $canonical['fact_type'] ?? null,
                'value' => $canonical['value'] ?? null,
                'unit' => $canonical['unit'] ?? null,
            ];
        }
        $geometry = is_array($payload['geometry_expert'] ?? null) ? $payload['geometry_expert'] : [];
        $quarantined = array_values(array_filter([
            ...(is_array($arbitration['quarantined_intents'] ?? null) ? $arbitration['quarantined_intents'] : []),
            ...(is_array($geometry['quarantined_intents'] ?? null) ? $geometry['quarantined_intents'] : []),
        ], 'is_array'));
        $questions = array_map(
            static fn ($question): array => $question->toArray(),
            app(ClarificationQuestionProjector::class)->projectPages([$payload]),
        );

        return [
            'analysis_complete' => count($completion) === 4 && ! in_array(false, $completion, true),
            'observations' => array_values(array_map(static fn (array $item): array => [
                'type' => is_string($item['type'] ?? null) ? $item['type'] : 'unknown',
                'label' => is_string($item['label'] ?? null) ? $item['label'] : null,
                'evidence_ref' => is_string($item['evidence_ref'] ?? null) ? $item['evidence_ref'] : null,
                'region' => is_array($item['polygon'] ?? null) ? $item['polygon'] : [],
            ], $elements)),
            'facts' => $facts,
            'questions' => $questions,
            'quarantined_items' => $quarantined,
        ];
    }

    private static function actualExecutionCount(mixed $unit): ?int
    {
        $metadata = is_array($unit->metadata) ? $unit->metadata : [];
        if (is_int($metadata['actual_execution_count'] ?? null)) {
            return max(0, $metadata['actual_execution_count']);
        }

        return $unit->status->value === 'failed' ? null : max(0, (int) $unit->attempt_count);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizedPayload(mixed $page): array
    {
        return is_array($page->normalized_payload) ? $page->normalized_payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function geometryPayload(mixed $page): array
    {
        $payload = self::normalizedPayload($page);

        return is_array($payload['geometry'] ?? null) ? $payload['geometry'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function pageUnderstanding(mixed $page): array
    {
        $payload = self::normalizedPayload($page);

        return is_array($payload['page_understanding'] ?? null) ? $payload['page_understanding'] : [];
    }

    private static function pageRole(mixed $page): string
    {
        $understanding = self::pageUnderstanding($page);
        $geometry = self::geometryPayload($page);

        return (string) ($understanding['page_role'] ?? $geometry['page_role'] ?? 'technical_document');
    }

    private static function roleForEstimation(mixed $page): string
    {
        $understanding = self::pageUnderstanding($page);

        return (string) ($understanding['role_for_estimation'] ?? 'context_document');
    }

    /**
     * @return array<string, mixed>
     */
    private static function reviewPayload(mixed $page): array
    {
        $understanding = self::pageUnderstanding($page);
        $reasons = array_values(array_map(
            'strval',
            is_array($understanding['review_reasons'] ?? null) ? $understanding['review_reasons'] : []
        ));

        return [
            'required' => (bool) ($understanding['review_required'] ?? ($reasons !== [])),
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function visualMetrics(mixed $page): array
    {
        $geometry = self::geometryPayload($page);

        return is_array($geometry['visual_metrics'] ?? null) ? $geometry['visual_metrics'] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function overlayPayload(mixed $page): array
    {
        $geometry = self::geometryPayload($page);

        return array_values(array_filter(
            is_array($geometry['overlay'] ?? null) ? $geometry['overlay'] : [],
            'is_array'
        ));
    }
}
