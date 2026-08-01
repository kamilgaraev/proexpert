<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportSubscription);

        return [
            'id' => $this->resource->id,
            'saved_view_id' => $this->resource->savedViewId,
            'report_code' => $this->resource->reportCode,
            'frequency' => $this->resource->frequency->value,
            'weekday' => $this->resource->weekday,
            'day_of_month' => $this->resource->dayOfMonth,
            'local_time' => $this->resource->localTime,
            'timezone' => $this->resource->timezone->getName(),
            'period_policy' => $this->resource->periodPolicy,
            'format' => $this->resource->format,
            'channel' => 'in_app',
            'status' => $this->resource->status->value,
            'disabled_reason' => $this->resource->disabledReason,
            'consecutive_failures' => $this->resource->consecutiveFailures,
            'next_run_at' => $this->resource->nextRunAt?->format(DATE_ATOM),
            'created_at' => $this->resource->createdAt->format(DATE_ATOM),
            'updated_at' => $this->resource->updatedAt->format(DATE_ATOM),
        ];
    }
}
