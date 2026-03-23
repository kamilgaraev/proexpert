<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Estimate;

use Illuminate\Http\Resources\Json\JsonResource;

class EstimateCoverageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'estimate_id' => $this['estimate_id'],
            'total_work_items' => $this['total_work_items'],
            'coverage_status' => $this['coverage_status'],
            'legacy_contract_id' => $this['legacy_contract_id'],
            'primary_contract' => $this['primary_contract'],
            'contracts' => $this['contracts'],
        ];
    }
}
