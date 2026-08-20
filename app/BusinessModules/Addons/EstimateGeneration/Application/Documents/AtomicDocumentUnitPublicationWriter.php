<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactConfidence;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\CanonicalFactReducer;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ClaimSemanticMatcher;
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
        private AcceptedDocumentFactProjector $factProjector = new AcceptedDocumentFactProjector,
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
        (new CanonicalFactReducer)->assertReduced($publication->claims, $publication->decisions);
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
        $requestedAreaConfidence = null;
        $confidenceAggregator = new CanonicalFactConfidence;
        foreach ($publication->decisions as $decision) {
            if ($decision->status !== 'accepted') {
                continue;
            }
            $claim = $claims[$decision->claimId] ?? throw new LogicException('document_fact_projection_claim_missing');
            $projected = $this->factProjector->project($claim);
            if ($projected === null) {
                continue;
            }
            if ($projected['fact_type'] === 'total_area'
                && $projected['unit'] === 'm2'
                && ($claim->value['type'] ?? null) === 'number'
                && CanonicalSourceDecimal::isPositive($claim->value['data'])) {
                $requestedArea = $claim;
                $requestedAreaConfidence = $confidenceAggregator->forDecision($decision, $claims);
            }
            $lineage = $this->lineage($decision, $claims);
            $confidence = $confidenceAggregator->forDecision($decision, $claims);
            $projectionKey = 'sha256:'.hash('sha256', implode('|', [
                (new ClaimSemanticMatcher)->key($claim),
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
                'label' => trans_message('estimate_generation.accepted_fact_projection.labels.'.$projected['label_key']),
                'value_text' => $projected['value_text'],
                'value_number' => $projected['value_number'],
                'unit' => $projected['unit'],
                'confidence' => $confidence,
                'source_ref' => json_encode([
                    'document_id' => $documentId,
                    'page_id' => (int) $page->id,
                    'page_number' => $pageNumber,
                    'source_version' => $sourceVersion,
                    'evidence_ref' => $claim->evidenceRef,
                    'evidence_refs' => $decision->evidenceRefs,
                    'lineage' => $lineage,
                    'locator' => $claim->locator,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'normalized_payload' => json_encode([
                    'projection' => 'arbiter_consensus:v1',
                    'projection_key' => $projectionKey,
                    'claim_id' => $claim->id,
                    'claim_ids' => $decision->supportingClaimIds,
                    'observer_roles' => array_values(array_unique(array_column($lineage, 'role'))),
                    'source_confidences' => array_column($lineage, 'confidence', 'claim_id'),
                    'confidence' => $confidence,
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
            $requestedAreaConfidence,
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
        ?float $confidence,
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
            'confidence' => $confidence ?? 0.0,
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

    private function lineage(\App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision $decision, array $claims): array
    {
        $lineage = [];
        foreach ($decision->supportingClaimIds as $claimId) {
            $supporting = $claims[$claimId] ?? null;
            if (! $supporting instanceof ObservationClaim) {
                continue;
            }
            $lineage[] = [
                'claim_id' => $supporting->id,
                'role' => $supporting->observerRole,
                'confidence' => $supporting->confidence,
                'evidence_ref' => $supporting->evidenceRef,
                'locator' => $supporting->locator,
            ];
        }

        return $lineage;
    }
}
