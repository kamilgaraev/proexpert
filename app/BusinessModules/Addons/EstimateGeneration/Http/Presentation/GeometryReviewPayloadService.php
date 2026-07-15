<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;

final readonly class GeometryReviewPayloadService implements GeometryReviewPayloadReader
{
    public function __construct(
        private GeometryReviewDataSource $data,
        private GeometryReviewSourcePresenter $sources,
    ) {}

    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session, int $page = 1, int $perPage = 20): array
    {
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->getKey();
        $head = $this->data->latestModel($organizationId, $projectId, $sessionId);
        $model = $head === null ? null : NormalizedBuildingModelData::fromArray($head['model'])->toArray();
        $sourcePage = $this->data->sourcePage($organizationId, $projectId, $sessionId, $page, $perPage);
        $presentedSources = [];
        foreach ($sourcePage['rows'] as $row) {
            $locator = is_array($row->locator)
                ? $row->locator
                : json_decode((string) $row->locator, true);
            $directRaster = in_array((string) $row->unit_type, ['raster_image', 'sketch'], true)
                && in_array((string) $row->mime_type, ['image/png', 'image/jpeg'], true);
            $generatedRaster = ! $directRaster
                && is_array($locator)
                && is_string($locator['artifact_path'] ?? null);
            $source = $this->sources->present([
                'document_id' => $row->document_id,
                'page_id' => $row->page_id,
                'page_number' => $row->page_number,
                'filename' => $row->filename,
                'width' => $row->width,
                'height' => $row->height,
                'artifact_path' => is_array($locator) && is_string($locator['artifact_path'] ?? null)
                    ? $locator['artifact_path']
                    : ($directRaster ? $row->storage_path : null),
                'content_type' => is_array($locator) && is_string($locator['content_type'] ?? null)
                    ? $locator['content_type']
                    : ($directRaster ? $row->mime_type : null),
                'normalized_payload' => $row->normalized_payload,
            ], $organizationId, $sessionId, $generatedRaster ? 'generated' : 'direct', $generatedRaster
                ? sprintf(
                    'org-%d/estimate-generation/sessions/%d/documents/%d/manifests/',
                    $organizationId,
                    $sessionId,
                    (int) $row->document_id,
                )
                : (string) $row->storage_path);
            if ($source !== null) {
                $presentedSources[] = $source;
            }
        }

        return [
            'state_version' => (int) $session->state_version,
            'model_version' => $head['content_version'] ?? null,
            'input_version' => $head['input_version'] ?? null,
            'building_model' => $model,
            'sources' => $presentedSources,
            'sources_meta' => [
                'total' => $sourcePage['total'],
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($sourcePage['total'] / $perPage)),
            ],
        ];
    }
}
