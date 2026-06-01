<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainCompactSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementChainCompactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProcurementChainCompactSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
