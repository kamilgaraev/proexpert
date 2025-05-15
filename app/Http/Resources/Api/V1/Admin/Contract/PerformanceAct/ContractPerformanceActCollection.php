<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContractPerformanceActCollection extends ResourceCollection
{
    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = ContractPerformanceActResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    /**
     * Get additional data that should be returned with the resource array.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return [
            // Можно добавить мета-информацию для коллекции, если необходимо
            // Например, общую сумму по актам, если это нужно на уровне коллекции
        ];
    }
} 