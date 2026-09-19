<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContractActivationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->resource;
        if (isset($data['plan'])) {
            $data['plan']['terms'] = (object) $data['plan']['terms'];
            $data['plan']['bases'] = (object) $data['plan']['bases'];
        }
        if (isset($data['result']['terms'])) {
            $data['result']['terms'] = (object) $data['result']['terms'];
        }

        return $data;
    }
}
