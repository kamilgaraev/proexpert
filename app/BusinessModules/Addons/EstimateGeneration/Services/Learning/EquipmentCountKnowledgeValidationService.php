<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationLearningExample;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimateNorm;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\ResolveSelectedNormativeResources;
use Illuminate\Validation\ValidationException;

final readonly class EquipmentCountKnowledgeValidationService
{
    private const PRODUCER = 'estimate_generation.equipment_count_claim.v1';

    public function __construct(
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
        private NativeNumericFactProof $nativeProof,
        private CurrentDraftQuantityProofGuard $quantities,
    ) {}

    public function capture(EstimateGenerationSession $session, array $item, int $normId, bool $checkNormative = true): ?array
    {
        $current = $this->currentItem($session, $item['key'] ?? null);
        $basis = $current['quantity_evidence'] ?? $current['quantity_basis_details'] ?? [];
        if (($basis['formula_key'] ?? null) !== 'equipment_count'
            && ! in_array(data_get($item, 'metadata.quantity_key'), ['equipment.install', 'equipment.purchase'], true)) {
            return null;
        }
        $result = ['schema_version' => 1, 'producer' => self::PRODUCER,
            'work_item_key' => $current['key'] ?? $item['key'] ?? null,
            'claim' => ['attribute' => 'equipment_count'],
            'count_source_check' => ['outcome' => 'unresolved', 'predicate' => NativeNumericFactProof::class,
                'reason' => 'current_independent_count_source_missing'],
            'normative_association_check' => ['outcome' => 'unresolved', 'predicate' => ResolveSelectedNormativeResources::class],
            'checked_at' => now()->toIso8601String()];
        if ($current === null || $session->project_id === null || ($basis['formula_key'] ?? null) !== 'equipment_count') {
            return $result;
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        if (count($snapshot->facts) > 10000) {
            return $result;
        }
        $operands = $basis['formula_inputs']['operands'] ?? [];
        if (! is_array($operands) || ! array_is_list($operands) || count($operands) !== 1 || ! is_string($operands[0]['fact_id'] ?? null)) {
            return $result;
        }
        $answer = array_column($snapshot->facts, null, 'id')[$operands[0]['fact_id']] ?? null;
        if (! $answer instanceof Fact || $answer->type !== 'equipment_count' || $answer->status !== 'confirmed'
            || ! in_array($answer->origin, ['user_assumption', 'user_input'], true)
            || $answer->entityId !== ($basis['formula_inputs']['entity_id'] ?? null)) {
            return $result;
        }
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, [$answer->id]]);
        if (count($decisions) !== 1 || $decisions[0]->actorType !== 'user'
            || $decisions[0]->sourceVersion !== $answer->sourceVersion || $answer->supersedesFactId === null) {
            return $result;
        }
        $source = $this->models->factsByIds(...[...$scope, [$answer->supersedesFactId]])[0] ?? null;
        $result['claim'] = ['attribute' => 'equipment_count', 'answer_fact_id' => $answer->id,
            'answer_decision_id' => $decisions[0]->id, 'source_count_fact_id' => $source?->id,
            'entity_id' => $answer->entityId, 'source_version' => $answer->sourceVersion,
            'value' => $answer->value, 'unit' => $answer->unit];
        if (! $source instanceof Fact || $source->origin !== 'document' || $source->status !== 'confirmed'
            || $source->type !== 'equipment_count' || $source->id === $answer->id
            || [$source->entityId, $source->sourceVersion] !== [$answer->entityId, $answer->sourceVersion]
            || FactVocabulary::engineeringObservation('equipment:knowledge', 'equipment_count', $answer->value, $answer->unit) === null
            || FactVocabulary::engineeringObservation('equipment:knowledge', 'equipment_count', $source->value, $source->unit) === null) {
            return $result;
        }
        $anchors = GeometryInventory::currentDocumentVersions($snapshot, false)[$source->entityId] ?? [];
        $proofs = [];
        foreach ($source->evidenceIds as $evidenceId) {
            if (! isset($anchors['evidence'][$evidenceId]) || preg_match('/\Aevidence:([1-9][0-9]*)\z/', $evidenceId, $match) !== 1) {
                continue;
            }
            $node = $this->evidence->node(...[...$scope, (int) $match[1]]);
            if ($node === null || $node->invalidatedAt !== null) {
                continue;
            }
            $proof = new Evidence($evidenceId, ...[...$scope, $node->sourceVersion, $node->sourceRef, $node->sourceType->value,
                $node->locator['page'] ?? $node->locator['unit_index'] ?? null, $node->locator['region'] ?? null,
                $node->locator['native_reference'] ?? null, $node->producerName, $node->sourceRef, null, $node->fingerprint]);
            if ($this->nativeProof->matches($source, $proof)) {
                $proofs[$evidenceId] = $node->fingerprint;
            }
        }
        if ($proofs === []) {
            return $result;
        }
        try {
            $this->quantities->assertCurrent(...[...$scope,
                ['local_estimates' => [['sections' => [['work_items' => [$current]]]]]], false]);
        } catch (ValidationException) {
            return $result;
        }
        $equal = FactVocabulary::measurementSignature((string) $answer->value, $answer->unit)
            === FactVocabulary::measurementSignature((string) $source->value, $source->unit);
        $result['count_source_check'] = ['outcome' => $equal ? 'passed' : 'failed',
            'predicate' => NativeNumericFactProof::class, 'source_fact_version' => $source->version,
            'evidence_fingerprints' => $proofs];
        if ($equal && $checkNormative) {
            $result['normative_association_check'] = $this->association($session, $current, $normId);
        }

        return $result;
    }

    public function permitsSourceReuse(EstimateGenerationLearningExample $example): bool
    {
        $stored = $example->context_payload['knowledge_validation'] ?? null;
        if (! is_array($stored) || ($stored['schema_version'] ?? null) !== 1 || ($stored['producer'] ?? null) !== self::PRODUCER
            || data_get($stored, 'count_source_check.outcome') !== 'passed'
            || data_get($stored, 'normative_association_check.outcome') !== 'passed') {
            return false;
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $example->organization_id)
            ->where('project_id', $example->project_id)->whereKey($example->generation_session_id)->first();
        if ($session === null || $example->estimate_norm_id === null) {
            return false;
        }
        $fresh = $this->capture($session, ['key' => $stored['work_item_key'] ?? null], (int) $example->estimate_norm_id, false);

        return data_get($fresh, 'count_source_check.outcome') === 'passed'
            && CanonicalPipelineJson::encode($stored['claim'] ?? null) === CanonicalPipelineJson::encode($fresh['claim'] ?? null);
    }

    public function currentAssociationPasses(array $item, int $normId, array $context): bool
    {
        if (! isset($context['organization_id'], $context['project_id'], $context['session_id'])) {
            return false;
        }
        $session = EstimateGenerationSession::query()->where('organization_id', $context['organization_id'])
            ->where('project_id', $context['project_id'])->whereKey($context['session_id'])->first();
        $current = $session === null ? null : $this->currentItem($session, $item['key'] ?? null);

        return $current !== null && $this->association($session, $current, $normId)['outcome'] === 'passed';
    }

    private function association(EstimateGenerationSession $session, array $item, int $normId): array
    {
        $regional = $session->draft_payload['regional_context'] ?? $session->input_payload['regional_context'] ?? [];
        $norm = EstimateNorm::query()->with('collection')->find($normId);
        $resolved = ! is_string($regional['normative_dataset_version'] ?? null) ? null
            : app(ResolveSelectedNormativeResources::class)->resolve($item, $normId, [
            'organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id,
            'session_id' => (int) $session->id, 'regional_context' => $regional,
            'object_type' => data_get($session->draft_payload, 'object_profile.object_type')
                ?? data_get($session->analysis_payload, 'object_profile.object_type') ?? $session->input_payload['building_type'] ?? null,
        ]);

        return ['outcome' => ($resolved['normative_match']['hard_gate_passed'] ?? false) === true ? 'passed' : 'unresolved',
            'predicate' => ResolveSelectedNormativeResources::class, 'estimate_norm_id' => $normId,
            'normative_dataset_version_id' => $norm?->collection?->dataset_version_id,
            'catalog_identity' => $resolved['normative_match']['catalog_identity'] ?? null];
    }

    private function currentItem(EstimateGenerationSession $session, mixed $key): ?array
    {
        $matches = [];
        foreach ($session->draft_payload['local_estimates'] ?? [] as $estimate) {
            foreach ($estimate['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    if (is_string($key) && ($item['key'] ?? null) === $key) {
                        $matches[] = $item;
                    }
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
