<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CurrentWorkEntityBindingProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final readonly class CurrentDraftQuantityProofGuard
{
    public function __construct(private CurrentProjectDerivedQuantityService $current,
        private WorkItemQuantityMapper $mapper, private EvidenceRepository $evidence, private ProjectModelRepository $models,
        private ?CurrentWorkEntityBindingProof $bindings = null) {}

    public function assertCurrent(int $organizationId, int $projectId, int $sessionId, array $draft, bool $lockEvidence = true): void
    {
        $universal = ($draft['generation_contract'] ?? null) === UniversalDraftProjector::CONTRACT;
        $capture = $this->models->snapshotForPlanning($organizationId, $projectId, $sessionId, 10001);
        $snapshot = $capture['snapshot'];
        $factIds = $universal ? \App\BusinessModules\Addons\EstimateGeneration\Quantities\NativeQuantityDecisionInputs::factIds($snapshot)
            : array_column($snapshot->facts, 'id');
        $decisions = $this->models->decisionsForSelectedFacts($organizationId, $projectId, $sessionId, $factIds);
        if (count($snapshot->facts) > 10000 || ($universal && count($decisions) > 256)) {
            $this->reject();
        }
        $quantities = $universal
            ? $this->current->simulateInput($snapshot, $capture['token'], $decisions)['quantities']
            : $this->current->previewInput($organizationId, $projectId, $sessionId)['quantities'];
        $documentVersions = GeometryInventory::currentDocumentVersions($snapshot);
        $wallScopes = WallEstimateScope::select($snapshot, $decisions, $documentVersions);
        $openingSnapshot = GeometryRelationOverlay::factIds($snapshot) === [] ? $snapshot
            : GeometryRelationOverlay::apply($snapshot, $decisions, $documentVersions);
        $openingScopes = OpeningEstimateScope::select($openingSnapshot, $decisions, $documentVersions);
        $openingSystems = \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::select($openingSnapshot, $decisions, $documentVersions);
        $ids = [];
        $bound = [];
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
                    if (isset($item['metadata']['scope_entity_binding'])
                        || ($item['metadata']['generation_source'] ?? null) === 'accepted_supplementary_work') {
                        $bound[] = $item;
                    }
                    $basis = $item['quantity_evidence'] ?? $item['quantity_basis_details'] ?? [];
                    $key = $item['metadata']['quantity_key'] ?? $basis['key'] ?? null;
                    $fresh = is_string($key) ? $this->mapper->map($key, $quantities) : null;
                    $entity = $basis['formula_inputs']['entity_id'] ?? null;
                    $targetEntity = $item['metadata']['scope_entity_id'] ?? $item['entity_id'] ?? null;
                    if ($fresh === null && is_string($key) && is_string($targetEntity) && $targetEntity !== '') {
                        $fresh = $this->mapper->mapForEntity($key, $quantities, $targetEntity);
                    }
                    if ($fresh === null || $fresh->reviewBlockers !== [] || $fresh->evidenceIds === [] || ! is_string($entity)
                        || ($fresh->formulaInputs['entity_id'] ?? null) !== $entity
                        || ($targetEntity !== null && (! is_string($targetEntity) || $targetEntity === '' || $targetEntity !== $entity))
                        || ((isset(FactVocabulary::engineeringFormulas()[$fresh->formulaKey]) || isset(FactVocabulary::foundationFormulas()[$fresh->formulaKey]))
                            && (! is_string($targetEntity) || $targetEntity === ''))) {
                        $this->reject();
                    }
                    if ($fresh->formulaKey === 'direct_quantity') {
                        foreach ($fresh->formulaInputs['operands'] ?? [] as $operand) {
                            if (($operand['quantity_binding']['work_item_key'] ?? null) !== ($item['key'] ?? null)) {
                                $this->reject();
                            }
                        }
                    }
                    if (! WallWorkItemExpander::permits($item, $wallScopes, $universal) || ! OpeningWorkItemExpander::permits($item, $openingScopes)
                        || ($universal && ! \App\BusinessModules\Addons\EstimateGeneration\Quantities\OpeningSystemScope::permits($item, $openingSystems))) {
                        $this->reject();
                    }
                    if (isset($item['metadata']['wall_assembly'])) {
                        foreach ($wallScopes[$targetEntity]['evidence_ids'] ?? [] as $id) {
                            if (preg_match('/\Aevidence:([1-9][0-9]*)\z/', $id, $match) !== 1) {
                                $this->reject();
                            }
                            $ids[(int) $match[1]] = (int) $match[1];
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
        if ($bound !== []) {
            $session = EstimateGenerationSession::query()->where('organization_id', $organizationId)
                ->where('project_id', $projectId)->whereKey($sessionId)->firstOrFail();
            foreach (($this->bindings ?? app(CurrentWorkEntityBindingProof::class))->evidenceIds($session, $bound, $snapshot, requireBindings: true) as $id) {
                $ids[(int) substr($id, 9)] = (int) substr($id, 9);
            }
        }
        if ($rows === 0 || $ids === []) {
            $this->reject();
        }
        // Keep actual source nodes locked through ordinary estimate publication.
        foreach (array_chunk(array_values($ids), 1000) as $chunk) {
            $active = $lockEvidence
                ? count($this->evidence->activeNodesForUpdate($organizationId, $projectId, $sessionId, $chunk))
                : \Illuminate\Support\Facades\DB::table('estimate_generation_evidence')->where('organization_id', $organizationId)
                    ->where('project_id', $projectId)->where('session_id', $sessionId)->whereIn('id', $chunk)->whereNull('invalidated_at')->count();
            if ($active !== count($chunk)) {
                $this->reject();
            }
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['draft' => [trans_message('estimate_generation.stage6_snapshot_stale')]]);
    }
}
