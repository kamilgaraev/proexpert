<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EstimateGenerationSession
 */
class EstimateGenerationSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var EstimateGenerationSession $session */
        $session = $this->resource;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'processing_stage' => $session->processing_stage,
            'processing_progress' => $session->processing_progress,
            'input' => $session->input_payload ?? [],
            'analysis' => $session->analysis_payload ?? [],
            'problem_flags' => $session->problem_flags ?? [],
            'applied_estimate_id' => $session->applied_estimate_id,
            'documents' => $this->whenLoaded('documents', function (): array {
                return $this->documents->map(static fn ($document): array => [
                    'id' => $document->id,
                    'filename' => $document->filename,
                    'mime_type' => $document->mime_type,
                    'meta' => $document->meta ?? [],
                ])->all();
            }, []),
            'created_at' => $session->created_at,
            'updated_at' => $session->updated_at,
        ];
    }
}
