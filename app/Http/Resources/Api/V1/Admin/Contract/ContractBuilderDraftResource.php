<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\Contract;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContractBuilderDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $draft = $this->resource;
        foreach ($draft['blocks'] as &$block) {
            $block['content']['variables'] = (object) $block['content']['variables'];
        }
        unset($block);
        foreach (['definitions', 'blocks', 'values', 'entity_snapshots'] as $key) {
            $draft[$key] = (object) $draft[$key];
        }

        return $draft;
    }
}
