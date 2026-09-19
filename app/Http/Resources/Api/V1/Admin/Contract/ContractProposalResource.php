<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContractProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;
        if (isset($proposal['content'])) {
            $proposal['content'] = (new ContractBuilderDraftResource($proposal['content']))->resolve($request);
        }

        return $proposal;
    }
}
