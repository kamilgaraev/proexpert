<?php

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContractCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = ContractResource::class;

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
            // Вы можете добавить метаданные пагинации здесь, если они доступны
            // 'links' => [
            //     'self' => 'link-values',
            // ],
            // 'meta' => [
            //     // 'current_page' => $this->currentPage(),
            //     // 'from' => $this->firstItem(),
            //     // 'last_page' => $this->lastPage(),
            //     // 'path' => $this->path(),
            //     // 'per_page' => $this->perPage(),
            //     // 'to' => $this->lastItem(),
            //     // 'total' => $this->total(),
            // ],
        ];
    }
} 