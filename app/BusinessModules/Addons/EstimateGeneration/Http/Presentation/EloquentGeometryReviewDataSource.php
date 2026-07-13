<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use Illuminate\Database\DatabaseManager;

final readonly class EloquentGeometryReviewDataSource implements GeometryReviewDataSource
{
    public function __construct(private DatabaseManager $database) {}

    public function latestModel(int $organizationId, int $projectId, int $sessionId): ?array
    {
        $row = $this->database->table('estimate_generation_building_models')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->latest('id')
            ->first(['content_version', 'input_version', 'model']);
        if ($row === null) {
            return null;
        }
        $model = is_array($row->model) ? $row->model : json_decode((string) $row->model, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($model)) {
            return null;
        }

        return [
            'content_version' => (string) $row->content_version,
            'input_version' => (string) $row->input_version,
            'model' => $model,
        ];
    }

    public function sourcePage(int $organizationId, int $projectId, int $sessionId, int $page, int $perPage): array
    {
        $query = $this->database->table('estimate_generation_processing_units as units')
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
            ->where('documents.status', '<>', 'ignored');
        $total = (clone $query)->count('pages.id');
        $rows = $query
            ->orderBy('units.document_id')
            ->orderBy('units.unit_index')
            ->orderBy('pages.id')
            ->forPage($page, $perPage)
            ->get([
                'units.document_id', 'units.unit_type', 'pages.id as page_id', 'pages.page_number', 'documents.filename',
                'documents.storage_path', 'documents.mime_type', 'pages.width', 'pages.height', 'units.locator', 'pages.normalized_payload',
            ]);

        return ['total' => $total, 'rows' => array_values($rows->all())];
    }
}
