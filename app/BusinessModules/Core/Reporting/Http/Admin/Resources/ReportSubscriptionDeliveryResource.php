<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportSubscriptionDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportSubscriptionDelivery);

        return [
            'id' => $this->resource->id,
            'subscription_id' => $this->resource->subscriptionId,
            'trigger' => $this->resource->trigger->value,
            'scheduled_for' => $this->resource->scheduledFor->format(DATE_ATOM),
            'run_id' => $this->resource->runId,
            'export_id' => $this->resource->exportId,
            'attempt' => $this->resource->attempt,
            'status' => $this->resource->status->value,
            'safe_error_code' => $this->resource->safeErrorCode,
            'created_at' => $this->resource->createdAt?->format(DATE_ATOM),
            'updated_at' => $this->resource->updatedAt?->format(DATE_ATOM),
            'expires_at' => $this->resource->executionExpiresAt->format(DATE_ATOM),
        ];
    }
}
