<?php

namespace App\Http\Resources\Api\V1\Mobile\SiteRequest;

use Illuminate\Http\Resources\Json\ResourceCollection;

class SiteRequestCollection extends ResourceCollection
{
    public $collects = SiteRequestResource::class;

    public function toArray($request)
    {
        return parent::toArray($request);
    }
} 