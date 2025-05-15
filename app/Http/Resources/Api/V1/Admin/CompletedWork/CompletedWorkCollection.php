<?php

namespace App\Http\Resources\Api\V1\Admin\CompletedWork;

use Illuminate\Http\Resources\Json\ResourceCollection;

class CompletedWorkCollection extends ResourceCollection
{
    public $collects = CompletedWorkResource::class;

    public function toArray($request)
    {
        // Laravel автоматически обрабатывает пагинацию для ResourceCollection,
        // добавляя 'data', 'links', и 'meta' ключи.
        // Таким образом, просто вернуть parent::toArray($request) достаточно.
        return parent::toArray($request);
    }
} 