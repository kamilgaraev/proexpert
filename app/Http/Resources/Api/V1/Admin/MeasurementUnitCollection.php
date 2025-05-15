<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MeasurementUnitCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = MeasurementUnitResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'data' => $this->collection,
            // Можно будет добавить метаданные пагинации, если $this->resource это LengthAwarePaginator
            'links' => [
                'first' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->url(1) : null,
                'last' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->url($this->resource->lastPage()) : null,
                'prev' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->previousPageUrl() : null,
                'next' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->nextPageUrl() : null,
            ],
            'meta' => [
                'current_page' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->currentPage() : null,
                'from' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->firstItem() : null,
                'last_page' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->lastPage() : null,
                'path' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->path() : null,
                'per_page' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->perPage() : null,
                'to' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->lastItem() : null,
                'total' => $this->resource instanceof \Illuminate\Pagination\AbstractPaginator ? $this->resource->total() : null,
            ],
        ];
    }
} 