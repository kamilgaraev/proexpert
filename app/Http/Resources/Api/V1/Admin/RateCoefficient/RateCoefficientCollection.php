<?php

namespace App\Http\Resources\Api\V1\Admin\RateCoefficient;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class RateCoefficientCollection extends ResourceCollection
{
    /**
     * The resource that this collection collects.
     *
     * @var string
     */
    public $collects = RateCoefficientResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        // Эта информация будет добавлена, если коллекция является результатом пагинации
        if ($this->resource instanceof \Illuminate\Pagination\AbstractPaginator) {
            return [
                'links' => $this->paginationLinks(),
                'meta' => $this->meta(),
            ];
        }
        return [];
    }

    /**
     * Get the pagination links for the response.
     *
     * @return array<string, string|null>
     */
    protected function paginationLinks(): array
    {
        return [
            'first' => $this->resource->url(1),
            'last' => $this->resource->url($this->resource->lastPage()),
            'prev' => $this->resource->previousPageUrl(),
            'next' => $this->resource->nextPageUrl(),
        ];
    }

    /**
     * Gather the meta data for the response.
     *
     * @return array<string, mixed>
     */
    protected function meta(): array
    {
        return [
            'current_page' => $this->resource->currentPage(),
            'from' => $this->resource->firstItem(),
            'last_page' => $this->resource->lastPage(),
            'path' => $this->resource->path(),
            'per_page' => $this->resource->perPage(),
            'to' => $this->resource->lastItem(),
            'total' => $this->resource->total(),
        ];
    }
} 