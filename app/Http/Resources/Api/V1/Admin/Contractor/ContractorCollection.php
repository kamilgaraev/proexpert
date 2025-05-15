<?php

namespace App\Http\Resources\Api\V1\Admin\Contractor;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContractorCollection extends ResourceCollection
{
    public $collects = ContractorResource::class;

    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    public function with(Request $request): array
    {
        return [
            // Можно добавить мета-информацию, если необходимо
        ];
    }
} 