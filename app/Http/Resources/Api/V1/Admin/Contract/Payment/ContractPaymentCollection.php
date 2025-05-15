<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ContractPaymentCollection extends ResourceCollection
{
    public $collects = ContractPaymentResource::class;

    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }

    public function with(Request $request): array
    {
        return [
            // Можно добавить мета-информацию для коллекции, если необходимо
        ];
    }
} 