<?php

namespace App\BusinessModules\Addons\AIEstimates\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIGenerationHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ],
            'input' => [
                'description' => $this->input_description,
                'parameters' => $this->input_parameters,
                'files' => $this->uploaded_files,
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            'result' => [
                'draft' => $this->generated_estimate_draft,
                'confidence_score' => $this->confidence_score,
            ],
            'metadata' => [
                'tokens_used' => $this->tokens_used,
                'cost_rub' => $this->cost,
                'processing_time_seconds' => $this->getProcessingTimeSeconds(),
            ],
            'error_message' => $this->error_message,
            'has_feedback' => $this->hasFeedback(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
