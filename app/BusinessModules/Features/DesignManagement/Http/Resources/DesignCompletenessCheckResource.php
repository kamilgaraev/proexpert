<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignCompletenessCheck;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignCompletenessCheckResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignCompletenessCheck $check */
        $check = $this->resource;
        $status = $this->enumValue($check->status);

        return [
            'id' => $check->id,
            'organization_id' => $check->organization_id,
            'project_id' => $check->project_id,
            'package_id' => $check->package_id,
            'created_by' => $check->created_by,
            'status' => $status,
            'status_label' => trans_message("design_management.completeness_statuses.{$status}"),
            'profile_code' => $check->profile_code,
            'project_stage' => $this->enumValue($check->project_stage),
            'object_type' => $this->enumValue($check->object_type),
            'checked_at' => $check->checked_at?->toIso8601String(),
            'blocking_count' => (int) $check->blocking_count,
            'warning_count' => (int) $check->warning_count,
            'summary' => $check->summary ?? [],
            'results' => $check->results ?? [],
            'metadata' => $check->metadata ?? [],
            'created_at' => $check->created_at?->toIso8601String(),
            'updated_at' => $check->updated_at?->toIso8601String(),
        ];
    }

    private function enumValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? $value->value : ($value !== null ? (string) $value : null);
    }
}
