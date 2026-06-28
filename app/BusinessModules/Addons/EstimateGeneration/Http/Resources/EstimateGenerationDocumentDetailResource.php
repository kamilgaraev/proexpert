<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
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

        $payload['pages'] = $this->whenLoaded('pages', function () use ($document): array {
            return $document->pages->map(static fn ($page): array => [
                'id' => $page->id,
                'page_number' => $page->page_number,
                'width' => $page->width,
                'height' => $page->height,
                'rotation' => $page->rotation,
                'language_codes' => $page->language_codes ?? [],
                'text' => $page->text,
                'text_hash' => $page->text_hash,
                'confidence' => $page->confidence,
                'normalized_payload' => $page->normalized_payload ?? [],
                'quality_flags' => $page->quality_flags ?? [],
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
                'confidence' => $fact->confidence,
                'source_ref' => $fact->source_ref ?? [],
                'normalized_payload' => $fact->normalized_payload ?? [],
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
                'confidence' => $element->confidence,
                'source_ref' => $element->source_ref ?? [],
                'normalized_payload' => $element->normalized_payload ?? [],
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
                'confidence' => $takeoff->confidence,
                'source_refs' => $takeoff->source_refs ?? [],
                'normalized_payload' => $takeoff->normalized_payload ?? [],
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
                'confidence' => $inference->confidence,
                'review_required' => $inference->review_required,
                'accepted_at' => $inference->accepted_at?->toISOString(),
            ])->all();
        }, []);

        return $payload;
    }
}
