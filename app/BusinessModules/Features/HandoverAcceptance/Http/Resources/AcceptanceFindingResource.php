<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceFinding;
use App\BusinessModules\Features\QualityControl\Http\Resources\QualityDefectResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcceptanceFinding */
final class AcceptanceFindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $finding = $this->resource;

        if (!$finding instanceof AcceptanceFinding) {
            return [];
        }

        return [
            'id' => $finding->id,
            'acceptance_scope_id' => $finding->acceptance_scope_id,
            'acceptance_session_id' => $finding->acceptance_session_id,
            'quality_defect_id' => $finding->quality_defect_id,
            'title' => $finding->title,
            'description' => $finding->description,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'resolution_comment' => $finding->resolution_comment,
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'quality_defect' => $finding->relationLoaded('qualityDefect') && $finding->qualityDefect ? new QualityDefectResource($finding->qualityDefect) : null,
        ];
    }
}
