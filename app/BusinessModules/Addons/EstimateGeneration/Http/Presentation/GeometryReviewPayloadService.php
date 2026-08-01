<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometrySourceConfirmationFactory;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use JsonException;

final readonly class GeometryReviewPayloadService implements GeometryReviewPayloadReader
{
    public function __construct(
        private GeometryReviewDataSource $data,
        private GeometryReviewSourcePresenter $sources,
        private GeometrySourceConfirmationFactory $sourceConfirmation,
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
                'source_version' => $row->source_version,
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
                $sourceConfirmation = $this->sourceConfirmation($row);
                $source['source_confirmation'] = $sourceConfirmation['payload'];
                if ($sourceConfirmation['reason'] !== null) {
                    $source['source_confirmation_unavailable_reason'] = $sourceConfirmation['reason'];
                }
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

    /** @return array{payload: array<string, mixed>|null, reason: string|null} */
    private function sourceConfirmation(object $row): array
    {
        $sourceVersion = is_string($row->source_version ?? null) ? $row->source_version : null;
        if ($sourceVersion === null || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1
            || ! $this->currentSourceVersions($row, $sourceVersion)) {
            return ['payload' => null, 'reason' => 'source_not_current'];
        }
        if (($row->unit_status ?? null) !== 'completed') {
            return ['payload' => null, 'reason' => 'source_not_complete'];
        }
        if (($row->unit_type ?? null) !== 'cad_drawing') {
            return ['payload' => null, 'reason' => 'semantic_confirmation_unavailable'];
        }
        if (! is_numeric($row->source_evidence_id ?? null) || (int) $row->source_evidence_id < 1
            || ! is_numeric($row->source_evidence_count ?? null) || (int) $row->source_evidence_count !== 1) {
            return ['payload' => null, 'reason' => 'source_evidence_unavailable'];
        }
        try {
            $normalizedPayload = is_array($row->normalized_payload ?? null)
                ? $row->normalized_payload
                : json_decode((string) ($row->normalized_payload ?? ''), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['payload' => null, 'reason' => 'vector_capture_invalid'];
        }
        if (! is_array($normalizedPayload)) {
            return ['payload' => null, 'reason' => 'vector_capture_invalid'];
        }
        if (array_key_exists('source_kind', $normalizedPayload) && ($normalizedPayload['source_kind'] ?? null) !== 'cad') {
            return ['payload' => null, 'reason' => 'semantic_confirmation_unavailable'];
        }
        $payload = $this->sourceConfirmation->makeFromNormalizedPayload($normalizedPayload, $sourceVersion);

        return $payload === null
            ? ['payload' => null, 'reason' => 'semantic_confirmation_unavailable']
            : ['payload' => $payload, 'reason' => null];
    }

    private function currentSourceVersions(object $row, string $sourceVersion): bool
    {
        foreach (['document_source_version', 'unit_source_version', 'page_source_version'] as $field) {
            $value = $row->{$field} ?? null;
            if (! is_string($value) || ! hash_equals($sourceVersion, $value)) {
                return false;
            }
        }

        return true;
    }
}
