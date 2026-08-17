<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use Illuminate\Database\Connection;
use LogicException;

final readonly class AtomicDocumentUnitPublicationWriter implements DocumentUnitPublicationWriter
{
    public function __construct(
        private Connection $database,
        private ProjectModelEvidenceWriter $evidence,
    ) {}

    public function transaction(int $organizationId, int $sessionId, callable $callback): mixed
    {
        return $this->evidence->transaction($organizationId, $sessionId, $callback);
    }

    public function write(
        DocumentUnitPublication $publication,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        int $pageNumber,
        string $sourceVersion,
    ): void {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('document_unit_publication_transaction_required');
        }
        $publication->assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        if ($publication->claims === []) {
            return;
        }
        $this->evidence->writeArbitration(
            $publication->claims,
            $publication->decisions,
            $documentId,
            $pageNumber,
        );
        $this->writeAcceptedDocumentFacts(
            $publication,
            $organizationId,
            $projectId,
            $sessionId,
            $documentId,
            $pageNumber,
            $sourceVersion,
        );
    }

    private function writeAcceptedDocumentFacts(
        DocumentUnitPublication $publication,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        int $pageNumber,
        string $sourceVersion,
    ): void {
        $page = $this->database->table('estimate_generation_document_pages')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('document_id', $documentId)
            ->where('page_number', $pageNumber)
            ->where('source_version', $sourceVersion)
            ->lockForUpdate()
            ->first(['id']);
        if ($page === null) {
            throw new LogicException('document_fact_projection_page_missing');
        }

        $claims = [];
        foreach ($publication->claims as $claim) {
            $claims[$claim->id] = $claim;
        }
        $rows = [];
        $requestedArea = null;
        foreach ($publication->decisions as $decision) {
            if ($decision->status !== 'accepted') {
                continue;
            }
            $claim = $claims[$decision->claimId] ?? throw new LogicException('document_fact_projection_claim_missing');
            $projected = $this->documentFact($claim);
            if ($projected === null) {
                continue;
            }
            if ($claim->entityKey === 'building_area_total'
                && $claim->factType === 'area'
                && $claim->unit === 'm2'
                && ($claim->value['type'] ?? null) === 'number'
                && CanonicalSourceDecimal::isPositive($claim->value['data'])) {
                $requestedArea = $claim;
            }
            $projectionKey = 'sha256:'.hash('sha256', implode('|', [
                $claim->id,
                $sourceVersion,
                (string) $documentId,
                (string) $pageNumber,
            ]));
            $rows[] = [
                'document_id' => $documentId,
                'page_id' => (int) $page->id,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'fact_type' => $projected['fact_type'],
                'scope_key' => mb_substr($claim->entityKey, 0, 255),
                'label' => $projected['label'],
                'value_text' => $projected['value_text'],
                'value_number' => $projected['value_number'],
                'unit' => $claim->unit,
                'confidence' => 1.0,
                'source_ref' => json_encode([
                    'document_id' => $documentId,
                    'page_id' => (int) $page->id,
                    'page_number' => $pageNumber,
                    'source_version' => $sourceVersion,
                    'evidence_ref' => $claim->evidenceRef,
                    'locator' => $claim->locator,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'normalized_payload' => json_encode([
                    'projection' => 'arbiter_consensus:v1',
                    'projection_key' => $projectionKey,
                    'claim_id' => $claim->id,
                    'observer_role' => $claim->observerRole,
                    'fact_type' => $claim->factType,
                    'value' => $claim->value,
                    'decision_reason_code' => $decision->reasonCode,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->database->table('estimate_generation_document_facts')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('document_id', $documentId)
            ->where('page_id', (int) $page->id)
            ->where('normalized_payload->projection', 'arbiter_consensus:v1')
            ->delete();
        if ($rows !== []) {
            $this->database->table('estimate_generation_document_facts')->insert($rows);
        }
        $this->writeRequestedAreaTakeoff(
            $requestedArea,
            $organizationId,
            $projectId,
            $sessionId,
            $documentId,
            (int) $page->id,
            $pageNumber,
            $sourceVersion,
        );
    }

    private function writeRequestedAreaTakeoff(
        ?ObservationClaim $area,
        int $organizationId,
        int $projectId,
        int $sessionId,
        int $documentId,
        int $pageId,
        int $pageNumber,
        string $sourceVersion,
    ): void {
        $this->database->table('estimate_generation_quantity_takeoffs')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('session_id', $sessionId)
            ->where('document_id', $documentId)
            ->where('page_id', $pageId)
            ->where('normalized_payload->projection', 'requested_area_takeoff:v1')
            ->delete();
        if ($area === null) {
            return;
        }
        $input = $this->database->table('estimate_generation_sessions')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('id', $sessionId)
            ->value('input_payload');
        if (is_string($input)) {
            $input = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
        }
        $description = is_array($input) && is_string($input['description'] ?? null)
            ? mb_strtolower(trim($input['description']))
            : '';
        if ($description === '' || mb_strlen($description) > 10_000
            || mb_stripos($description, 'стяжк') === false
            || mb_stripos($description, 'пол') === false) {
            return;
        }

        $this->database->table('estimate_generation_quantity_takeoffs')->insert([
            'session_id' => $sessionId,
            'document_id' => $documentId,
            'page_id' => $pageId,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'source_element_ids' => json_encode([], JSON_THROW_ON_ERROR),
            'scope_key' => 'rough_floor_area',
            'work_intent' => json_encode([
                'scope' => 'rough_finishing',
                'operation' => 'cement_screed',
                'basis' => 'user_request_and_accepted_area',
            ], JSON_THROW_ON_ERROR),
            'name' => trans_message('estimate_generation.accepted_fact_projection.requested_area_takeoff_name'),
            'unit' => 'm2',
            'quantity' => $area->value['data'],
            'formula' => 'accepted_building_area_total',
            'confidence' => 1.0,
            'source_refs' => json_encode([[
                'document_id' => $documentId,
                'page_id' => $pageId,
                'page_number' => $pageNumber,
                'source_version' => $sourceVersion,
                'evidence_ref' => $area->evidenceRef,
            ]], JSON_THROW_ON_ERROR),
            'normalized_payload' => json_encode([
                'projection' => 'requested_area_takeoff:v1',
                'quantity_key' => 'rough.floor',
                'claim_id' => $area->id,
                'source_version' => $sourceVersion,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{fact_type:string,label:string,value_text:?string,value_number:string|null}|null */
    private function documentFact(ObservationClaim $claim): ?array
    {
        $value = $claim->value['data'];
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        $isNumber = ($claim->value['type'] ?? null) === 'number';
        if ($isNumber && (! CanonicalSourceDecimal::isValid($value)
            || ($claim->factType !== 'elevation' && ! CanonicalSourceDecimal::isNonNegative($value)))) {
            return null;
        }
        $numeric = $isNumber;
        $type = match ($claim->factType) {
            'area' => $numeric
                ? ($claim->entityKey === 'building_area_total'
                    ? 'total_area'
                    : (str_starts_with($claim->entityKey, 'room:') ? 'room_area' : 'zone_area'))
                : 'dimension',
            'dimension_chain' => 'dimension',
            'elevation' => 'height',
            'level' => 'floor_count',
            'material', 'finish_zone' => 'material',
            'technology_candidate', 'roof_geometry' => 'work_scope',
            'table' => 'table_row',
            'note' => 'note',
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return [
            'fact_type' => $type,
            'label' => trans_message('estimate_generation.accepted_fact_projection.labels.'.match ($type) {
                'room_area', 'zone_area' => 'area',
                default => $type,
            }),
            'value_text' => $numeric ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
            'value_number' => $numeric ? $value : null,
        ];
    }
}
