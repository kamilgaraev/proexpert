<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MaterialCollection extends ResourceCollection
{
    public $collects = MaterialResource::class;

    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
            // TODO: Добавить пагинацию, если необходимо
        ];
    }
} 