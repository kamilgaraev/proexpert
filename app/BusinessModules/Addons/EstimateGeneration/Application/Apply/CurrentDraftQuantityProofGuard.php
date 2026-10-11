<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final readonly class CurrentDraftQuantityProofGuard
{
    public function __construct(private CurrentProjectDerivedQuantityService $current,
        private WorkItemQuantityMapper $mapper, private EvidenceRepository $evidence) {}

    public function assertCurrent(int $organizationId, int $projectId, int $sessionId, array $draft): void
    {
        $quantities = $this->current->previewInput($organizationId, $projectId, $sessionId)['quantities'];
        $ids = [];
        $rows = 0;
        foreach ($draft['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'resource_note', 'review_note', 'quantity_review'], true)) {
                        continue;
                    }
                    if (++$rows > 10000) {
                        $this->reject();
                    }
                    $basis = $item['quantity_evidence'] ?? $item['quantity_basis_details'] ?? [];
                    $key = $item['metadata']['quantity_key'] ?? $basis['key'] ?? null;
                    $fresh = is_string($key) ? $this->mapper->map($key, $quantities) : null;
                    $entity = $basis['formula_inputs']['entity_id'] ?? null;
                    if ($fresh === null || $fresh->reviewBlockers !== [] || $fresh->evidenceIds === [] || ! is_string($entity)
                        || ($fresh->formulaInputs['entity_id'] ?? null) !== $entity) {
                        $this->reject();
                    }
                    if ($fresh->formulaKey === 'direct_quantity') {
                        foreach ($fresh->formulaInputs['operands'] ?? [] as $operand) {
                            if (($operand['quantity_binding']['work_item_key'] ?? null) !== ($item['key'] ?? null)) {
                                $this->reject();
                            }
                        }
                    }
                    $basisEvidence = $basis['evidence_ids'] ?? [];
                    $freshEvidence = $fresh->evidenceIds;
                    if (! is_array($basisEvidence) || ! array_is_list($basisEvidence)) {
                        $this->reject();
                    }
                    sort($basisEvidence, SORT_STRING);
                    sort($freshEvidence, SORT_STRING);
                    if ($basisEvidence !== $freshEvidence || ($basis['formula_key'] ?? null) !== $fresh->formulaKey
                        || ($basis['formula_version'] ?? null) !== $fresh->formulaVersion
                        || CanonicalPipelineJson::encode($basis['formula_inputs']['operands'] ?? null)
                            !== CanonicalPipelineJson::encode($fresh->formulaInputs['operands'] ?? null)) {
                        $this->reject();
                    }
                    $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($fresh->unit, (string) ($item['unit'] ?? ''));
                    if ($factor === null || ! is_numeric($item['quantity'] ?? null)
                        || ! BigDecimal::of($fresh->amount)->multipliedBy($factor)->isEqualTo(BigDecimal::of((string) $item['quantity']))) {
                        $this->reject();
                    }
                    // Source-reference hashes do not identify real evidence and cannot support an applied volume.
                    foreach ($fresh->evidenceIds as $id) {
                        if (preg_match('/\Aevidence:([1-9][0-9]*)\z/', $id, $match) !== 1) {
                            $this->reject();
                        }
                        $ids[(int) $match[1]] = (int) $match[1];
                    }
                }
            }
        }
        if ($rows === 0 || $ids === []) {
            $this->reject();
        }
        // Keep actual source nodes locked through ordinary estimate publication.
        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            if (count($this->evidence->activeNodesForUpdate($organizationId, $projectId, $sessionId, $chunk)) !== count($chunk)) {
                $this->reject();
            }
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['draft' => [trans_message('estimate_generation.stage6_snapshot_stale')]]);
    }
}
