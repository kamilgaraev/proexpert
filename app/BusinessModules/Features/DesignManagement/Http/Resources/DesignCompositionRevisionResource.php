<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Resources;

use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DesignCompositionRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'package_id' => $this->package_id, 'revision_number' => $this->revision_number, 'current_revision_number' => $this->revision_number, 'status' => $this->status, 'composition' => $this->composition, 'effective_composition' => $this->effectiveComposition(), 'fingerprint' => $this->fingerprint, 'available_actions' => $this->actions(), 'needs_review_reason' => $this->needs_review_reason, 'created_at' => $this->created_at?->toIso8601String(), 'author' => $this->whenLoaded('author', fn () => ['id' => $this->author?->id, 'name' => $this->author?->name]), 'approved_at' => $this->approved_at?->toIso8601String(), 'approved_by' => $this->whenLoaded('approvedBy', fn () => ['id' => $this->approvedBy?->id, 'name' => $this->approvedBy?->name]), 'exclusions' => $this->whenLoaded('exclusions', fn () => $this->exclusions->map(fn ($item) => ['id' => $item->id, 'item_key' => $item->item_key, 'reason' => $item->reason, 'created_at' => $item->created_at?->toIso8601String(), 'author' => $item->author ? ['id' => $item->author->id, 'name' => $item->author->name] : null])->values())];
    }

    private function actions(): array
    {
        $user = request()->user();
        if ($user === null) {
            return [];
        }
        $editable = DesignPackage::query()
            ->whereKey($this->package_id)
            ->where('organization_id', $this->organization_id)
            ->where('project_id', $this->project_id)
            ->where('composition_revision_id', $this->id)
            ->whereNotIn('status', ['issued', 'archived'])
            ->exists();
        if (! $editable) {
            return [];
        }
        $auth = app(AuthorizationService::class);
        $context = ['organization_id' => $this->organization_id, 'project_id' => $this->project_id];
        $actions = [];
        if ($auth->can($user, 'design-management.composition.edit', $context)) {
            array_push($actions, 'create_revision', 'needs_review');
            if ($this->status !== 'approved') {
                $actions[] = 'exclusions';
            }
        }
        if ($this->status !== 'approved' && $auth->can($user, 'design-management.composition.approve', $context)) {
            array_unshift($actions, 'approve');
        }

        return $actions;
    }

    private function effectiveComposition(): array
    {
        $composition = is_array($this->composition) ? $this->composition : [];
        $excluded = $this->resource->relationLoaded('exclusions') ? $this->exclusions->pluck('item_key')->all() : [];
        foreach (['sections', 'document_groups', 'items'] as $key) if (is_array($composition[$key] ?? null)) $composition[$key] = array_values(array_filter($composition[$key], static fn (mixed $item): bool => ! is_array($item) || ! in_array((string) ($item['code'] ?? ''), $excluded, true)));
        return $composition;
    }
}
