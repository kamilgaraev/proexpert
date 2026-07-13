<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationBuildingModel;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\DatabaseManager;

final readonly class GeometryReviewPayloadService
{
    public function __construct(
        private DatabaseManager $database,
        private GeometryReviewSourcePresenter $sources,
    ) {}

    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session): array
    {
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->getKey();
        $head = EstimateGenerationBuildingModel::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first();
        $rows = $this->database->table('estimate_generation_processing_units as units')
            ->join('estimate_generation_documents as documents', function ($join): void {
                $join->on('documents.id', '=', 'units.document_id')
                    ->on('documents.organization_id', '=', 'units.organization_id')
                    ->on('documents.project_id', '=', 'units.project_id')
                    ->on('documents.session_id', '=', 'units.session_id')
                    ->on('documents.source_version', '=', 'units.source_version');
            })
            ->join('estimate_generation_document_pages as pages', function ($join): void {
                $join->on('pages.processing_unit_id', '=', 'units.id')
                    ->on('pages.document_id', '=', 'units.document_id')
                    ->on('pages.organization_id', '=', 'units.organization_id')
                    ->on('pages.project_id', '=', 'units.project_id')
                    ->on('pages.session_id', '=', 'units.session_id')
                    ->on('pages.source_version', '=', 'units.source_version');
            })
            ->where('units.organization_id', $organizationId)
            ->where('units.project_id', $projectId)
            ->where('units.session_id', $sessionId)
            ->where('units.status', 'completed')
            ->where('documents.status', '<>', 'ignored')
            ->orderBy('units.document_id')
            ->orderBy('units.unit_index')
            ->get([
                'units.document_id', 'units.unit_type', 'pages.id as page_id', 'pages.page_number', 'documents.filename',
                'documents.storage_path', 'documents.mime_type', 'pages.width', 'pages.height', 'units.locator', 'pages.normalized_payload',
            ]);
        $presentedSources = [];
        foreach ($rows as $row) {
            $locator = is_array($row->locator)
                ? $row->locator
                : json_decode((string) $row->locator, true);
            $directRaster = in_array((string) $row->unit_type, ['raster_image', 'sketch'], true)
                && in_array((string) $row->mime_type, ['image/png', 'image/jpeg'], true);
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
            ], $organizationId, $sessionId);
            if ($source !== null) {
                $presentedSources[] = $source;
            }
        }

        return [
            'state_version' => (int) $session->state_version,
            'model_version' => $head?->content_version,
            'input_version' => $head?->input_version,
            'building_model' => $head?->model,
            'sources' => $presentedSources,
        ];
    }
}
