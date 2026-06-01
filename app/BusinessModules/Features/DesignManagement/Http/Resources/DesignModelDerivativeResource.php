<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Enums\DesignDerivativeStatusEnum;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignModelDerivativeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var DesignModelDerivative $derivative */
        $derivative = $this->resource;
        $status = $this->enumValue($derivative->status);

        return [
            'id' => $derivative->id,
            'version_id' => $derivative->version_id,
            'viewer_provider' => $derivative->viewer_provider,
            'derivative_format' => $derivative->derivative_format,
            'status' => $status,
            'status_label' => trans_message("design_management.statuses.derivatives.{$status}"),
            'progress_percent' => (int) $derivative->progress_percent,
            'processing_stage' => $derivative->processing_stage,
            'failed_reason' => $derivative->failed_reason,
            'prepared_at' => $derivative->prepared_at?->toIso8601String(),
            'processing_started_at' => $derivative->processing_started_at?->toIso8601String(),
            'processing_finished_at' => $derivative->processing_finished_at?->toIso8601String(),
            'metadata' => $derivative->metadata ?? [],
            'created_at' => $derivative->created_at?->toIso8601String(),
            'updated_at' => $derivative->updated_at?->toIso8601String(),
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? $value->value : (string) ($value ?? DesignDerivativeStatusEnum::MISSING->value);
    }
}
