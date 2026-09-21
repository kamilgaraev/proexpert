<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use App\BusinessModules\Features\HandoverAcceptance\Models\WorkRework;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkReworkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rework = $this->resource;

        if (! $rework instanceof WorkRework) {
            return [];
        }

        return [
            'id' => $rework->id,
            'acceptance_scope_id' => $rework->acceptance_scope_id,
            'quantity_line_id' => $rework->quantity_line_id,
            'quantity' => $rework->quantity,
            'unit_id' => $rework->unit_id,
            'status' => $rework->status,
            'revision' => $rework->revision,
            'responsible_user_id' => $rework->responsible_user_id,
            'reason' => $rework->reason,
            'correction_description' => $rework->correction_description,
            'evidence_snapshot' => $this->safeEvidence($rework->evidence_snapshot),
            'financial_impact' => $rework->financial_impact,
            'submitted_at' => $rework->submitted_at?->toIso8601String(),
            'verified_at' => $rework->verified_at?->toIso8601String(),
        ];
    }

    private function safeEvidence(?array $snapshot): array
    {
        return array_values(array_map(static function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            return array_intersect_key($item, array_flip([
                'id', 'fileable_type', 'fileable_id', 'name', 'size', 'updated_at',
            ]));
        }, $snapshot ?? []));
    }
}
