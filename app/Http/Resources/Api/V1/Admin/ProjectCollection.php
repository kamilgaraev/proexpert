<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProjectCollection extends ResourceCollection
{
   /**
    * The resource that this collection collects.
    *
    * @var string
    */
    public $collects = ProjectResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            // Можно добавить метаданные пагинации, если используется
            // 'links' => [
            //     'self' => 'link-value',
            // ],
            // 'meta' => [
            //     'current_page' => $this->currentPage(),
            //     'from' => $this->firstItem(),
            //     'last_page' => $this->lastPage(),
            //     'path' => $this->path(),
            //     'per_page' => $this->perPage(),
            //     'to' => $this->lastItem(),
            //     'total' => $this->total(),
            // ],
        ];
    }
} 