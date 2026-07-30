<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportSubscriptionPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportSubscriptionPage);

        return [
            'items' => array_map(
                static fn ($subscription): array => (new ReportSubscriptionResource($subscription))->toArray($request),
                $this->resource->items,
            ),
            'meta' => [
                'limit' => $this->resource->limit,
                'next_cursor' => $this->resource->nextCursor,
                'has_more' => $this->resource->hasMore,
            ],
        ];
    }
}
