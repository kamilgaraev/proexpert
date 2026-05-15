<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcceptanceChecklist */
final class AcceptanceChecklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $checklist = $this->resource;

        if (!$checklist instanceof AcceptanceChecklist) {
            return [];
        }

        return [
            'id' => $checklist->id,
            'acceptance_scope_id' => $checklist->acceptance_scope_id,
            'title' => $checklist->title,
            'status' => $checklist->status,
            'items' => $checklist->relationLoaded('items') ? $checklist->items->map(fn ($item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'is_required' => $item->is_required,
                'status' => $item->status,
                'comment' => $item->comment,
            ])->values()->all() : [],
        ];
    }
}
