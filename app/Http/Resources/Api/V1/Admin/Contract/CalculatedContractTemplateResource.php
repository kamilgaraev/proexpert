<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CalculatedContractTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'template_id' => $this->resource['template_id'],
            'template_version' => $this->resource['template_version'],
            'values' => (object) $this->resource['values'],
            'html' => $this->resource['html'],
        ];
    }
}
