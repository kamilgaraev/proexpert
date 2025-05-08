<?php

namespace App\Http\Resources\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class AdvanceTransactionCollection extends ResourceCollection
{
    // Указываем, какой ресурс использовать для отдельных элементов коллекции
    public $collects = AdvanceTransactionResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Стандартный формат ответа для коллекций с пагинацией
        return [
            'data' => $this->collection,
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $this->currentPage(),
                'from' => $this->firstItem(),
                'last_page' => $this->lastPage(),
                'path' => $this->path(),
                'per_page' => $this->perPage(),
                'to' => $this->lastItem(),
                'total' => $this->total(),
            ],
        ];
    }
} 