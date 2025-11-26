<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * API Resource Collection для заявок
 */
class SiteRequestCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     */
    public $collects = SiteRequestResource::class;

    /**
     * Transform the resource collection into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}

