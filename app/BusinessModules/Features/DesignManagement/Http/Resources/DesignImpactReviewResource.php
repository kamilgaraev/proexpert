<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignImpactReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
