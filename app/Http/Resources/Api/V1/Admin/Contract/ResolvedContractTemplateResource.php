<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

final class ResolvedContractTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource, ['template_id', 'template_version', 'document', 'definitions', 'blocks', 'contract_profile_code']);
        foreach ($data['blocks'] as &$block) {
            $block['content']['variables'] = (object) $block['content']['variables'];
        }
        unset($block);
        $data['blocks'] = (object) $data['blocks'];
        $data['definitions'] = (object) $data['definitions'];

        return $data;
    }
}
