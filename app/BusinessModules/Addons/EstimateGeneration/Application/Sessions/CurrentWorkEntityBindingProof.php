<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\WorkEntityScopePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Illuminate\Validation\ValidationException;

final readonly class CurrentWorkEntityBindingProof
{
    public function __construct(private ProjectModelRepository $models, private CurrentWorkEntitySources $sources,
        private EvidenceRepository $evidence) {}

    public function evidenceIds(EstimateGenerationSession $session, array $items, ?ProjectModelSnapshot $snapshot = null,
        bool $requireBindings = false): array
    {
        $bound = $types = [];
        foreach ($items as $item) {
            if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'review_note', 'resource_note', 'quantity_review'], true)) {
                continue;
            }
            $binding = $item['metadata']['scope_entity_binding'] ?? null;
            if ($binding === null) {
                if ($requireBindings && ($item['metadata']['generation_source'] ?? null) === 'accepted_supplementary_work') {
                    $this->reject();
                }

                continue;
            }
            if (! is_array($binding) || ! is_string($item['key'] ?? null) || WorkEntityScopePolicy::types($item) === []) {
                $this->reject();
            }
            $bound[] = $item;
            array_push($types, ...WorkEntityScopePolicy::types($item));
        }
        if ($bound === []) {
            return [];
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        if ($snapshot === null) {
            $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        }
        if (count($snapshot->facts) > 10000) {
            $this->reject();
        }
        $facts = array_column($snapshot->facts, null, 'id');
        $factIds = array_map(static fn (array $item) => $item['metadata']['scope_entity_binding']['fact_id'] ?? '', $bound);
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, $factIds]);
        $choices = array_column($decisions, null, 'id');
        $sourceRows = array_column($this->sources->rowsForTypes($session, array_values(array_unique($types))), null, 'source_fact_id');
        $proofs = new EvaluationChoiceProof;
        $evidence = [];
        $bindingFacts = [];
        foreach ($bound as $item) {
            $binding = $item['metadata']['scope_entity_binding'];
            $stored = $session->input_payload['work_entity_selections'][$item['key']] ?? null;
            $fact = $facts[$binding['fact_id'] ?? ''] ?? null;
            $decision = $choices[$binding['decision_id'] ?? ''] ?? null;
            $source = $sourceRows[$binding['source_fact_id'] ?? ''] ?? null;
            $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', $scope));
            $anchor = 'entity:'.hash('sha256', $version.'|work-entity:'.$item['key']);
            $contract = $binding['binding_contract'] ?? null;
            $fields = ['answer_kind', 'work_item_key', 'entity_id', 'source_fact_id', 'source_fact_version', 'source_value_hash', 'source_version'];
            if ($contract === WorkEntityScopePolicy::BINDING_CONTRACT) {
                array_push($fields, 'binding_contract', 'source_entity_type');
            } elseif ($contract !== null || ($source['entity_type'] ?? null) !== 'room' || array_key_exists('source_entity_type', $binding)) {
                $this->reject();
            }
            if (! is_array($stored) || CanonicalPipelineJson::encode($stored) !== CanonicalPipelineJson::encode($binding)
                || $source === null || ! in_array($source['entity_type'], WorkEntityScopePolicy::types($item), true)
                || ($binding['answer_kind'] ?? null) !== 'work_entity_binding' || ($binding['work_item_key'] ?? null) !== $item['key']
                || ($binding['entity_id'] ?? null) !== ($item['metadata']['scope_entity_id'] ?? null)
                || ($binding['entity_id'] ?? null) !== $source['entity_id']
                || ($contract !== null && ($binding['source_entity_type'] ?? null) !== $source['entity_type'])
                || $fact === null || $decision === null || $fact->type !== 'evaluation_answer' || $fact->unit !== null
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope
                || $fact->sourceVersion !== $version || $fact->entityId !== $anchor
                || $fact->entityId !== ($binding['ledger_entity_id'] ?? null) || $decision->version !== $fact->version
                || (int) $decision->actorId < 1 || ! $proofs->accepted($snapshot, $fact, $decision)) {
                $this->reject();
            }
            foreach (['source_fact_id', 'source_fact_version', 'source_value_hash', 'source_version'] as $field) {
                if (($binding[$field] ?? null) !== $source[$field]) {
                    $this->reject();
                }
            }
            if (! is_array($fact->value) || array_diff(array_keys($fact->value), [...$fields, 'reason', 'decision_id']) !== []) {
                $this->reject();
            }
            foreach ($fields as $field) {
                if (! array_key_exists($field, $binding) || ! array_key_exists($field, $fact->value) || $binding[$field] !== $fact->value[$field]) {
                    $this->reject();
                }
            }
            foreach ($snapshot->conflicts as $conflict) {
                foreach ($conflict->status === 'unresolved' ? $conflict->facts : [] as $conflicting) {
                    if ([$conflicting->id, $conflicting->version, $conflicting->sourceVersion] === [$fact->id, $fact->version, $fact->sourceVersion]) {
                        $this->reject();
                    }
                }
            }
            foreach ([...$fact->evidenceIds, $source['evidence']['id']] as $id) {
                if (! is_string($id) || preg_match('/\Aevidence:[1-9][0-9]*\z/', $id) !== 1) {
                    $this->reject();
                }
                $evidence[$id] = $id;
            }
            $bindingFacts[$fact->id] = $fact;
        }
        if (count($evidence) > 50000) {
            $this->reject();
        }
        $nodes = [];
        foreach (array_chunk(array_map(static fn (string $id): int => (int) substr($id, 9), array_values($evidence)), 1000) as $chunk) {
            foreach ($this->evidence->activeNodesForUpdate(...[...$scope, $chunk]) as $node) {
                $nodes['evidence:'.$node->id] = $node;
            }
        }
        if (count($nodes) !== count($evidence)) {
            $this->reject();
        }
        foreach ($bindingFacts as $fact) {
            foreach ($fact->evidenceIds as $id) {
                $node = $nodes[$id];
                if ($node->type !== EvidenceType::SourceFact || $node->sourceType !== EvidenceSourceType::UserInput
                    || $node->sourceRef !== 'input:'.$session->id || $node->sourceVersion !== $fact->sourceVersion
                    || $node->producerName !== EvidenceProducer::UserInputNormalizer->value
                    || ($node->value['fact_key'] ?? null) !== 'evaluation_choice'
                    || ($node->value['fact_value'] ?? null) !== hash('sha256', CanonicalPipelineJson::encode($fact->value))) {
                    $this->reject();
                }
            }
        }

        return array_values($evidence);
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['entity' => trans_message('estimate_work_entity.binding_source_invalid')]);
    }
}
