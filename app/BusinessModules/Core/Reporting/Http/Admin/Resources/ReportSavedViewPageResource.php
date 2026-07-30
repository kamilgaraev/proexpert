<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportSavedViewPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportSavedViewPage);

        return ['items' => array_map(fn ($v) => (new ReportSavedViewResource($v))->toArray($request), $this->resource->items), 'limit' => $this->resource->limit, 'next_cursor' => $this->resource->nextCursor, 'has_more' => $this->resource->hasMore];
    }
}
