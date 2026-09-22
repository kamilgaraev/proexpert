<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignDocumentSheet;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignImpactReview;
use App\BusinessModules\Features\DesignManagement\Models\DesignSourceLink;
use App\Exceptions\BusinessLogicException;
use App\Models\EstimateItem;

final class WorkVolumeCoverageSourceEvidence
{
    public function resolve(int $organizationId, int $projectId, int $estimateItemId, ?int $sourceLinkId): ?array
    {
        if ($sourceLinkId === null) {
            return null;
        }

        return $this->resolveMany($organizationId, $projectId, [$sourceLinkId => $estimateItemId])[$sourceLinkId] ?? null;
    }

    public function resolveMany(int $organizationId, int $projectId, array $sourceLinkToEstimateItem): array
    {
        if ($sourceLinkToEstimateItem === []) {
            return [];
        }

        $itemIds = array_values(array_unique(array_map('intval', $sourceLinkToEstimateItem)));
        $items = EstimateItem::query()->with('estimate')->whereIn('id', $itemIds)->get()->keyBy('id');
        $links = DesignSourceLink::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->where('target_type', 'estimate_item')->where('status', 'active')
            ->whereIn('id', array_map('intval', array_keys($sourceLinkToEstimateItem)))->get()->keyBy('id');

        foreach ($sourceLinkToEstimateItem as $sourceLinkId => $estimateItemId) {
            $item = $items->get((int) $estimateItemId);
            $link = $links->get((int) $sourceLinkId);
            if ($item === null || (int) $item->estimate?->organization_id !== $organizationId || (int) $item->estimate?->project_id !== $projectId
                || $link === null || (int) $link->target_id !== (int) $estimateItemId) {
                throw $this->invalidSource();
            }
        }

        $sources = DesignArtifactVersion::query()->with('artifact.package')->where('organization_id', $organizationId)->where('project_id', $projectId)
            ->whereIn('id', $links->pluck('source_version_id'))->get()->keyBy('id');
        $sheetIds = $links->pluck('source_sheet_id')->filter()->map(fn ($id): int => (int) $id)->values();
        $sheets = DesignDocumentSheet::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereIn('id', $sheetIds)->get()->keyBy('id');
        $elementIds = $links->pluck('source_element_id')->filter()->map(fn ($id): int => (int) $id)->values();
        $elements = DesignIfcModelElement::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->whereIn('express_id', $elementIds)->get()->groupBy(fn (DesignIfcModelElement $element): string => $element->version_id.':'.$element->express_id);
        $reviews = DesignImpactReview::query()->where('organization_id', $organizationId)->where('project_id', $projectId)->where('status', 'pending')->whereIn('link_id', $links->keys())->get()->groupBy('link_id');

        $result = [];
        foreach ($sourceLinkToEstimateItem as $sourceLinkId => $estimateItemId) {
            $link = $links->get((int) $sourceLinkId);
            $source = $sources->get((int) $link->source_version_id);
            if ($source === null || (int) $source->artifact?->organization_id !== $organizationId || (int) $source->artifact?->project_id !== $projectId
                || (int) $source->artifact?->package?->organization_id !== $organizationId || (int) $source->artifact?->package?->project_id !== $projectId) {
                throw $this->invalidSource();
            }
            $sheet = $link->source_sheet_id === null ? null : $sheets->get((int) $link->source_sheet_id);
            if ($link->source_sheet_id !== null && ($sheet === null || (int) $sheet->version_id !== (int) $source->id || (int) $sheet->artifact_id !== (int) $source->artifact_id)) {
                throw $this->invalidSource();
            }
            $element = $link->source_element_id === null ? null : $elements->get($source->id.':'.$link->source_element_id)?->first();
            if ($link->source_element_id !== null && $element === null) {
                throw $this->invalidSource();
            }
            $result[(int) $sourceLinkId] = [
                'source_link_id' => (int) $link->id, 'source_link_row_version' => (int) $link->row_version,
                'source_snapshot' => is_array($link->source_snapshot) ? $link->source_snapshot : [],
                'source_version' => ['id' => (int) $source->id, 'artifact_id' => (int) $source->artifact_id, 'title' => (string) $source->title, 'version_number' => (string) $source->version_number, 'revision' => $source->revision_label ?? $source->revision],
                'source_sheet' => $sheet?->only(['id', 'sheet_number', 'sheet_title', 'revision']),
                'source_element' => $element?->only(['id', 'express_id', 'global_id', 'name', 'category']),
                'pending_impact_reviews' => $reviews->get($link->id, collect())->map(static fn (DesignImpactReview $review): array => ['id' => (int) $review->id, 'link_id' => (int) $review->link_id, 'previous_version_id' => (int) $review->previous_version_id, 'new_version_id' => (int) $review->new_version_id, 'status' => (string) $review->status])->values()->all(),
            ];
        }

        return $result;
    }

    private function invalidSource(): BusinessLogicException
    {
        return new BusinessLogicException(trans_message('budget_estimates.work_volume_statements.coverage_source_invalid'), 422);
    }
}
