<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentActionBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\EstimateGenerationDocumentPreviewService;
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
            'progress_percent' => (int) ($document->progress_percent ?? 0),
            'page_count' => $document->page_count,
            'processed_page_count' => (int) ($document->processed_page_count ?? 0),
            'quality' => [
                'score' => $document->quality_score,
                'level' => $document->quality_level,
                'flags' => $document->quality_flags ?? [],
            ],
            'document_understanding' => $this->documentUnderstanding($document),
            'facts_summary' => $document->facts_summary ?? [],
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
            'preview_url' => $user instanceof User
                ? app(EstimateGenerationDocumentPreviewService::class)->forDocument($document, $user)
                : null,
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
}
