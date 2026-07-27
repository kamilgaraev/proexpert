<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportExport);

        return [
            'id' => $this->resource->id,
            'run_id' => $this->resource->runId,
            'status' => $this->resource->status->value,
            'export_hash' => $this->resource->exportHash->value,
            'format' => $this->resource->format,
            'columns' => $this->resource->columns,
            'sort' => ['field' => $this->resource->sort->field, 'direction' => $this->resource->sort->direction->value],
            'locale' => $this->resource->locale,
            'timezone' => $this->resource->timezone->getName(),
            'size_bytes' => $this->resource->sizeBytes,
            'checksum' => $this->resource->checksum?->value,
            'created_at' => $this->resource->createdAt->format(DATE_ATOM),
            'updated_at' => $this->resource->updatedAt->format(DATE_ATOM),
            'ready_at' => $this->resource->readyAt?->format(DATE_ATOM),
            'expires_at' => $this->resource->expiresAt->format(DATE_ATOM),
            'cancel_requested_at' => $this->resource->cancelRequestedAt?->format(DATE_ATOM),
            'poll_after_ms' => $this->resource->pollAfterMs,
        ];
    }
}
