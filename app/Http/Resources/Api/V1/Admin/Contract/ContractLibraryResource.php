<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

final class ContractLibraryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = ['item' => Arr::only($this->resource['item'], [
            'id', 'organization_id', 'kind', 'is_archived', 'lock_version', 'created_at', 'updated_at',
        ])];
        if (isset($this->resource['version'])) {
            $result['version'] = Arr::only($this->resource['version'], [
                'id', 'item_id', 'version_number', 'title', 'content', 'status', 'created_by', 'created_at', 'published_by', 'published_at',
            ]);
            if (in_array($this->resource['item']['kind'], ['template', 'block'], true)) {
                $result['version']['content']['variables'] = (object) $result['version']['content']['variables'];
            }
        }

        return $result;
    }
}
