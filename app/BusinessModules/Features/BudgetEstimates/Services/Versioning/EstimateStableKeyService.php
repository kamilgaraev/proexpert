<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use Illuminate\Support\Str;

class EstimateStableKeyService
{
    public function ensureKeys(Estimate $estimate): void
    {
        $sections = EstimateSection::query()
            ->where('estimate_id', $estimate->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($sections as $section) {
            if ($section->stable_key) {
                continue;
            }

            $section->stable_key = Str::uuid()->toString();
            $section->saveQuietly();
        }

        $items = EstimateItem::query()
            ->where('estimate_id', $estimate->id)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            if ($item->stable_key) {
                continue;
            }

            $item->stable_key = Str::uuid()->toString();
            $item->saveQuietly();
        }

        $estimate->setRelation('sections', $sections->fresh());
        $estimate->setRelation('items', $items->fresh());
    }

    public function resolveItemKey(array $item): string
    {
        $stableKey = $item['stable_key'] ?? null;

        if (is_string($stableKey) && $stableKey !== '') {
            return $stableKey;
        }

        return (string) ($item['structural_key'] ?? $this->structuralItemKey($item));
    }

    private function structuralItemKey(array $item): string
    {
        $parts = [
            'item',
            $item['parent_work_stable_key'] ?? $item['parent_work_id'] ?? 'root',
            $item['estimate_section_stable_key'] ?? $item['estimate_section_id'] ?? 'unsectioned',
            $item['position_number'] ?? '',
            $item['normative_rate_code'] ?? '',
            $item['item_type'] ?? '',
            $item['name'] ?? '',
        ];

        return implode(':', array_map(
            static fn ($value): string => mb_strtolower(trim((string) $value)),
            $parts
        ));
    }
}
