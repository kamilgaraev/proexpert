<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProcurementChainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ProcurementChainSummary $summary */
        $summary = $this->resource;

        return $summary->toArray();
    }
}
