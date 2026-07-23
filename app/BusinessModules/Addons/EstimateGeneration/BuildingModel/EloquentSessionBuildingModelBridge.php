<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineBaseInputVersion;
use Illuminate\Database\Connection;

final readonly class EloquentSessionBuildingModelBridge
{
    public function __construct(
        private Connection $database,
        private SessionBuildingModelBridge $bridge,
        private DocumentTotalAreaConstraintResolver $areaConstraints = new DocumentTotalAreaConstraintResolver,
        private GenerationBuildingModelRefreshPolicy $refreshPolicy = new GenerationBuildingModelRefreshPolicy,
    ) {}

    public function rebuild(int $sessionId): void
    {
        $session = new EstimateGenerationSession;
        $session->setConnection($this->database->getName());
        $session = $session->newQuery()->with([
            'documents.facts',
            'documents.drawingElements',
            'documents.quantityTakeoffs',
            'documents.scopeInferences',
        ])->find($sessionId);
        if (! $session instanceof EstimateGenerationSession) {
            return;
        }

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
                    ->on('pages.source_version', '=', 'units.source_version');
            })
            ->where('units.organization_id', (int) $session->organization_id)
            ->where('units.project_id', (int) $session->project_id)
            ->where('units.session_id', (int) $session->id)
            ->where('units.status', 'completed')
            ->where('documents.status', '<>', 'ignored')
            ->whereIn('units.unit_type', ['pdf_page', 'cad_drawing', 'raster_image', 'sketch'])
            ->orderBy('units.document_id')->orderBy('units.unit_index')->orderBy('units.id')
            ->get([
                'units.id as unit_id', 'units.document_id', 'pages.id as page_id', 'units.unit_type',
                'units.unit_index', 'units.source_version', 'pages.confidence', 'pages.normalized_payload',
                'documents.filename as document_name',
            ]);

        $units = [];
        foreach ($rows as $row) {
            $payload = is_array($row->normalized_payload)
                ? $row->normalized_payload
                : json_decode((string) $row->normalized_payload, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($payload) && is_string($row->document_name) && trim($row->document_name) !== '') {
                $payload['document_name'] = $row->document_name;
            }
            $units[] = new SessionBuildingModelUnitData(
                (int) $row->unit_id,
                (int) $row->document_id,
                (int) $row->page_id,
                (string) $row->unit_type,
                (int) $row->unit_index,
                (string) $row->source_version,
                $row->confidence === null ? 0.0 : (float) $row->confidence,
                is_array($payload) ? $payload : [],
            );
        }

        $this->bridge->store(new BuildingModelOperationContext(
            (int) $session->organization_id,
            (int) $session->project_id,
            (int) $session->id,
            PipelineBaseInputVersion::fromSession($session),
        ), $units, $this->areaConstraint($session));
    }

    public function rebuildForGeneration(int $sessionId): void
    {
        $latestModel = $this->database->table('estimate_generation_building_models')
            ->where('session_id', $sessionId)
            ->orderByDesc('id')
            ->first(['id', 'organization_id', 'project_id', 'scale_status']);
        $hasActiveUserConfirmation = $latestModel !== null
            && $this->database->table('estimate_generation_building_model_evidence as links')
                ->join('estimate_generation_evidence as evidence', function ($join): void {
                    $join->on('evidence.id', '=', 'links.evidence_id')
                        ->on('evidence.organization_id', '=', 'links.organization_id')
                        ->on('evidence.project_id', '=', 'links.project_id')
                        ->on('evidence.session_id', '=', 'links.session_id');
                })
                ->where('links.building_model_id', (int) $latestModel->id)
                ->where('links.organization_id', (int) $latestModel->organization_id)
                ->where('links.project_id', (int) $latestModel->project_id)
                ->where('links.session_id', $sessionId)
                ->where('evidence.source_type', 'user_input')
                ->where('evidence.producer_name', 'user_input_normalizer')
                ->whereNull('evidence.invalidated_at')
                ->exists();
        if ($latestModel !== null && $this->refreshPolicy->preservesLatestModel(
            (string) $latestModel->scale_status,
            $hasActiveUserConfirmation,
        )) {
            return;
        }

        $this->rebuild($sessionId);
    }

    /** @return array<string, mixed>|null */
    private function areaConstraint(EstimateGenerationSession $session): ?array
    {
        $documents = [];
        foreach ($session->documents as $document) {
            $documents[] = [
                'id' => (int) $document->getKey(),
                'status' => (string) $document->status,
                'quality_level' => $document->quality_level,
                'quality_score' => $document->quality_score,
                'source_version' => (string) $document->source_version,
                'facts_summary' => is_array($document->facts_summary) ? $document->facts_summary : [],
            ];
        }
        $constraint = $this->areaConstraints->resolve($documents);
        if ($constraint === null) {
            return null;
        }
        $source = $constraint['sources'][0];

        return [
            'total_area_m2' => $constraint['total_area_m2'],
            'floor_count' => $constraint['floor_count'],
            'document_id' => $source['document_id'],
            'source_version' => $source['source_version'],
            'confidence' => $source['confidence'],
        ];
    }
}
