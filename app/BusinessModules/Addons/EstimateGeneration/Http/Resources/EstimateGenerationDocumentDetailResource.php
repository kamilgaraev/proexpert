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

        return $payload;
    }
}
