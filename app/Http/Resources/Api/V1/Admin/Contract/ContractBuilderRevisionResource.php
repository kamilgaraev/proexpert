<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

final class ContractBuilderRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource, [
            'id', 'contract_id', 'revision_number', 'base_revision_id', 'template_version_id',
            'author_organization_id', 'created_by', 'created_at', 'content_hash', 'document',
            'definitions', 'blocks', 'values', 'parties', 'attachments', 'entity_snapshots',
        ]);
        foreach ($data['blocks'] as &$block) {
            $block['content']['variables'] = (object) $block['content']['variables'];
        }
        unset($block);
        foreach (['definitions', 'blocks', 'values', 'entity_snapshots'] as $map) {
            $data[$map] = (object) $data[$map];
        }

        return $data;
    }
}
