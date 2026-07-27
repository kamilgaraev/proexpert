<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportSavedViewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportSavedView);

        return [
            'id' => $this->resource->id,
            'report_code' => $this->resource->reportCode,
            'contract_version' => $this->resource->contractVersion,
            'name' => $this->resource->name,
            'visibility' => $this->resource->visibility,
            'filters' => $this->resource->filters->values,
            'comparison' => $this->resource->comparison,
            'sort' => ['field' => $this->resource->sort->field, 'direction' => $this->resource->sort->direction->value],
            'columns' => $this->resource->columns,
            'status' => $this->resource->status,
            'is_default' => $this->resource->isDefault,
            'created_at' => $this->resource->createdAt->format(DATE_ATOM),
            'updated_at' => $this->resource->updatedAt->format(DATE_ATOM),
        ];
    }
}
