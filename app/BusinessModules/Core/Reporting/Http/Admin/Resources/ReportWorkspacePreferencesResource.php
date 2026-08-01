<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Resources;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReportWorkspacePreferencesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        assert($this->resource instanceof ReportWorkspacePreferences);

        return [
            'recent_report_codes' => $this->resource->recentReportCodes,
            'favourite_report_codes' => $this->resource->favouriteReportCodes,
            'display_preferences' => [
                'catalog_group_order' => $this->resource->display->catalogGroupOrder,
                'collapsed_catalog_groups' => $this->resource->display->collapsedCatalogGroups,
                'landing_section' => $this->resource->display->landingSection,
            ],
            'updated_at' => $this->resource->updatedAt->format(DATE_RFC3339),
        ];
    }
}
