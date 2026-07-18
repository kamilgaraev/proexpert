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
            ]);

        $units = [];
        foreach ($rows as $row) {
            $payload = is_array($row->normalized_payload)
                ? $row->normalized_payload
                : json_decode((string) $row->normalized_payload, true, 512, JSON_THROW_ON_ERROR);
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
