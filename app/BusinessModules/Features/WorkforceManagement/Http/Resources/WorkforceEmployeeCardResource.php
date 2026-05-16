<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkforceEmployeeCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return (array) $this->resource;
    }
}
