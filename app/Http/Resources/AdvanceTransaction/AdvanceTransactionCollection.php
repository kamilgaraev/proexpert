<?php

namespace App\Http\Resources\AdvanceTransaction;

use Illuminate\Http\Resources\Json\ResourceCollection;

class AdvanceTransactionCollection extends ResourceCollection
{
    /**
     * Ресурс, который нужно разрешить для коллекции.
     *
     * @var string
     */
    public $collects = AdvanceTransactionResource::class;

    /**
     * Преобразовать коллекцию ресурсов в массив.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->resource->total(),
                'count' => $this->resource->count(),
                'per_page' => $this->resource->perPage(),
                'current_page' => $this->resource->currentPage(),
                'total_pages' => $this->resource->lastPage(),
            ],
        ];
    }
} 