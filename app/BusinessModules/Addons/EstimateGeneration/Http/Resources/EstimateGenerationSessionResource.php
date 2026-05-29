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
        $input = $session->input_payload ?? [];
        $analysis = $session->analysis_payload ?? [];
        $regionalContext = $input['regional_context'] ?? $analysis['regional_context'] ?? null;

        return [
            'id' => $session->id,
            'status' => $session->status,
            'processing_stage' => $session->processing_stage,
            'processing_progress' => $session->processing_progress,
            'input' => $input,
            'analysis' => $analysis,
            'regional_context' => $regionalContext,
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
}
