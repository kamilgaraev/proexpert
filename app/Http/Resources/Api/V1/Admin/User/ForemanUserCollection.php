<?php

namespace App\Http\Resources\Api\V1\Admin\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ForemanUserCollection extends ResourceCollection
{
    /**
      * The resource that this resource collects.
      *
      * @var string
      */
     public $collects = ForemanUserResource::class;
     
    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
             'data' => $this->collection,
             // Optionally include pagination links/meta if the collection is paginated
             // 'links' => $this->when($this->resource instanceof \Illuminate\Pagination\AbstractPaginator, function () {
             //     return $this->resource->links(); // Or customize links
             // }),
             // 'meta' => $this->when($this->resource instanceof \Illuminate\Pagination\AbstractPaginator, function () {
             //     return [
             //         'current_page' => $this->resource->currentPage(),
             //         'from' => $this->resource->firstItem(),
             //         'last_page' => $this->resource->lastPage(),
             //         'path' => $this->resource->path(),
             //         'per_page' => $this->resource->perPage(),
             //         'to' => $this->resource->lastItem(),
             //         'total' => $this->resource->total(),
             //     ];
             // }),
         ];
    }
} 