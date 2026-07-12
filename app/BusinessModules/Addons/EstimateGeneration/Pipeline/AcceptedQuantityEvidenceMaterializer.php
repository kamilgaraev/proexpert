<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class AcceptedQuantityEvidenceMaterializer
{
    public function __construct(private EvidenceRepository $evidence, private Connection $database) {}

    public function materialize(CheckpointClaim $claim, PipelineStageResult $result): void
    {
        if ($result->stage !== ProcessingStage::PlanWorkItems || $claim->checkpointId === null) {
            return;
        }
        foreach ($result->transientData['local_estimates'] ?? [] as $localEstimate) {
            foreach ($localEstimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $workItem) {
                    $descriptor = $workItem['quantity_evidence_descriptor'] ?? null;
                    if (! is_array($descriptor)) {
                        continue;
                    }
                    $data = new EvidenceData(
                        organizationId: $claim->context->organizationId,
                        projectId: $claim->context->projectId,
                        sessionId: $claim->context->sessionId,
                        type: EvidenceType::WorkItem,
                        sourceType: EvidenceSourceType::from((string) $descriptor['source_type']),
                        sourceRef: (string) $descriptor['source_ref'],
                        sourceVersion: (string) $descriptor['source_version'],
                        locator: $descriptor['locator'],
                        value: [
                            'work_code' => (string) $descriptor['work_code'],
                            'quantity' => (string) $descriptor['quantity'],
                            'unit' => (string) $descriptor['unit'],
                        ],
                        confidence: (float) $descriptor['confidence'],
                        producerName: (string) $descriptor['producer_name'],
                        producerVersion: (string) $descriptor['producer_version'],
                    );
                    if (! hash_equals((string) $descriptor['fingerprint'], $data->fingerprint())) {
                        throw new RuntimeException('estimate_generation.accepted_evidence_descriptor_mismatch');
                    }
                    $node = $this->evidence->insertOrGet($data);
                    $this->database->table('estimate_generation_accepted_evidence')->insertOrIgnore([
                        'checkpoint_id' => $claim->checkpointId,
                        'organization_id' => $claim->context->organizationId,
                        'project_id' => $claim->context->projectId,
                        'session_id' => $claim->context->sessionId,
                        'output_version' => $result->outputVersion,
                        'descriptor_fingerprint' => $data->fingerprint(),
                        'evidence_id' => $node->id,
                        'created_at' => now(),
                    ]);
                }
            }
        }
    }
}
