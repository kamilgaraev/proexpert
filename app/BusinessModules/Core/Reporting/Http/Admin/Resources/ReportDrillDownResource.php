<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResourceLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportDrillDownResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportDrillDownResult);

        return [
            'rows' => $this->resource->rows,
            'next_cursor' => $this->resource->nextCursor,
            'resource_links' => array_map($this->resourceLink(...), $this->resource->resourceLinks),
        ];
    }

    private function resourceLink(ReportResourceLink $link): array
    {
        return [
            'resource_type' => $link->resourceType,
            'resource_id' => $link->resourceId,
            'route_name' => $link->routeName,
            'params' => $link->params,
            'availability' => $link->availability,
        ];
    }
}
