<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;

/** Conservative physical-source binding. Choice ledger writes cannot invalidate their own projection. */
final class EvaluationScenarioSourceBinding
{
    public function fingerprint(ProjectModelSnapshot $snapshot, array $decisions): string
    {
        $entities = array_filter($snapshot->entities, static fn (Entity $entity): bool => ! isset($entity->attributes['identity']['evaluation_anchor'])
            && ! isset($entity->attributes['identity']['work_item_key']));
        $entityIds = array_fill_keys(array_column($entities, 'id'), true);
        $facts = array_filter($snapshot->facts, static fn (Fact $fact): bool => isset($entityIds[$fact->entityId])
            && preg_match('/\Awork_quantity_[a-f0-9]{48}\z/', $fact->type) !== 1);
        $physicalIds = array_fill_keys(array_column($facts, 'entityId'), true);
        $entities = array_filter($entities, static fn (Entity $entity): bool => isset($physicalIds[$entity->id])
            || in_array($entity->type, ['room', 'wall', 'opening'], true));
        $factIds = array_fill_keys(array_column($facts, 'id'), true);
        $decisions = array_filter($decisions, static fn (Decision $decision): bool => isset($factIds[$decision->selectedFactId ?? '']));
        $evidenceIds = [];
        foreach ([...$facts, ...$decisions] as $record) {
            foreach ($record->evidenceIds as $id) {
                $evidenceIds[$id] = true;
            }
        }
        $evidence = array_filter($snapshot->evidence, static fn ($proof): bool => isset($evidenceIds[$proof->id]));
        $parts = ['entities' => $entities, 'facts' => $facts, 'evidence' => $evidence, 'decisions' => $decisions,
            'conflicts' => $snapshot->conflicts];
        foreach ($parts as &$records) {
            $records = array_column($records, null, 'id');
            ksort($records, SORT_STRING);
            $records = array_map(static fn (object $record): array => get_object_vars($record), $records);
        }
        unset($records);

        return hash('sha256', CanonicalPipelineJson::encode(['version' => ParametricEvaluationScenarios::VERSION, ...$parts]));
    }

    public function current(mixed $selection, ProjectModelSnapshot $snapshot, array $decisions): bool
    {
        if (! is_array($selection) || ! array_key_exists('parameter_projection', $selection)) {
            return true;
        }
        $projection = $selection['parameter_projection'];

        return is_array($projection) && ($projection['version'] ?? null) === ParametricEvaluationScenarios::VERSION
            && is_string($projection['source_fingerprint'] ?? null)
            && hash_equals($projection['source_fingerprint'], $this->fingerprint($snapshot, $decisions));
    }

    public function materializationMatches(array $before, array $after, array $selected, array $materialized,
        string $actorId, string $revisionId): bool
    {
        if (! ($before['snapshot'] ?? null) instanceof ProjectModelSnapshot || ! ($after['snapshot'] ?? null) instanceof ProjectModelSnapshot
            || ! is_array($before['token_state'] ?? null) || ! is_array($after['token_state'] ?? null)) {
            return false;
        }
        $stateKeys = ['scope', 'source_versions', 'entities', 'facts', 'bindings', 'evidence', 'decisions', 'role_runs'];
        if (array_diff($stateKeys, array_keys($before['token_state'])) !== [] || array_diff(array_keys($before['token_state']), $stateKeys) !== []
            || array_diff($stateKeys, array_keys($after['token_state'])) !== [] || array_diff(array_keys($after['token_state']), $stateKeys) !== []) {
            return false;
        }
        $oldFacts = array_column($before['snapshot']->facts, null, 'id');
        $newFacts = array_column($after['snapshot']->facts, null, 'id');
        $decisions = array_column($after['decisions'], null, 'selectedFactId');
        $entities = array_column($before['snapshot']->entities, null, 'id');
        $positions = array_column($selected['scenario']['positions'], null, 'key');
        $allowed = ['before' => ['facts' => [], 'evidence' => [], 'entities' => []], 'after' => ['facts' => [], 'evidence' => [], 'entities' => []]];
        $selections = $before['selections'];
        foreach ($after['selections'] as $key => $selection) {
            if (($selections[$key] ?? null) === $selection) {
                continue;
            }
            $position = $positions[$key] ?? null;
            $bindingId = is_array($selection) ? ($selection['fact_id'] ?? null) : null;
            $binding = is_string($bindingId) ? ($newFacts[$bindingId] ?? null) : null;
            $decision = $binding === null ? null : ($decisions[$binding->id] ?? null);
            $source = $binding === null || $decision === null ? null : (new DirectQuantityBinding)->source($after['snapshot'], $binding, $decision);
            if (! is_array($position) || ($position['quantity_status'] ?? null) !== 'assumed' || $source === null
                || isset($oldFacts[$binding->id]) || isset($oldFacts[$source->id])
                || ($selection['decision_id'] ?? null) !== $decision->id || $decision->actorId !== $actorId
                || ($binding->value['work_item_key'] ?? null) !== $key
                || ($binding->value['source_revision_id'] ?? null) !== $revisionId
                || ($binding->value['source_scenario_id'] ?? null) !== $selected['scenario_id']
                || ($binding->value['entered_value'] ?? null) !== $position['quantity']
                || ($binding->value['entered_unit'] ?? null) !== $position['unit']
                || ! (new EvaluationSelectionVerifier)->accepted($after['snapshot'], $after['decisions'], $selection, 'evaluation_answer',
                    array_diff_key($binding->value, array_flip(['reason', 'decision_id'])))) {
                return false;
            }
            $normalized = WorkQuantityInputUnit::normalize($position['quantity'], $position['unit']);
            $target = $entities[$position['entity_id'] ?? ''] ?? null;
            $sourceDecision = $decisions[$source->id] ?? null;
            $ledger = array_column($after['snapshot']->entities, null, 'id')[$binding->entityId] ?? null;
            if ($normalized === null || $target === null || $source->entityId !== $target->id || $source->sourceVersion !== $target->sourceVersion
                || $source->origin !== 'user_assumption' || $source->nativeNumericProofs !== [] || $source->unit !== $normalized['unit']
                || ! BigDecimal::of($source->value)->isEqualTo($normalized['value'])
                || $sourceDecision === null || $sourceDecision->actorType !== 'user' || $sourceDecision->actorId !== $actorId
                || $sourceDecision->targetType !== 'fact' || $sourceDecision->targetId !== ($source->supersedesFactId ?? $source->id)
                || $sourceDecision->reason !== $decision->reason || $sourceDecision->sourceVersion !== $source->sourceVersion
                || $sourceDecision->version !== $source->version
                || $sourceDecision->evidenceIds !== $source->evidenceIds
                || $ledger === null || ($ledger->attributes['identity']['evaluation_anchor'] ?? null) !== 'work:'.$key) {
                return false;
            }
            foreach ([$source, $binding] as $fact) {
                if (! $this->allowWrittenFact($fact, $oldFacts, $before['snapshot'], $after['snapshot'], $allowed)) {
                    return false;
                }
            }
            if (! isset($entities[$ledger->id])) {
                $allowed['after']['entities'][$ledger->id] = true;
            }
            $selections[$key] = $selection;
        }
        if ($allowed['after']['facts'] === [] || $selections !== $after['selections']
            || ! $this->writtenTokenStateCurrent($before['token_state'], $after['token_state'], $allowed['after']['facts'])) {
            return false;
        }
        $payload = $before['input_payload'];
        $payload['quantity_selections'] = $selections;
        $payload['quantity_selection_revision'] = ($payload['quantity_selection_revision'] ?? 0) + 1;
        unset($payload['confirmed_evaluation']);
        if (CanonicalPipelineJson::encode($payload) !== CanonicalPipelineJson::encode($after['input_payload'])) {
            return false;
        }
        foreach (['before' => $before, 'after' => $after] as $side => $capture) {
            foreach ($capture['snapshot']->facts as $fact) {
                if (! isset($allowed[$side]['facts'][$fact->id]) && array_intersect_key(array_fill_keys($fact->evidenceIds, true), $allowed[$side]['evidence']) !== []) {
                    return false;
                }
            }
        }
        if ($this->remainingSnapshot($before, $allowed['before']) !== $this->remainingSnapshot($after, $allowed['after'])
            || $this->remainingTokenState($before['token_state'], $allowed['before']) !== $this->remainingTokenState($after['token_state'], $allowed['after'])) {
            return false;
        }

        return $this->sameScenarioAfterRefresh($selected, $materialized, $before['token'], $after['token']);
    }

    private function allowWrittenFact(Fact $fact, array $oldFacts, ProjectModelSnapshot $before, ProjectModelSnapshot $after, array &$allowed): bool
    {
        $previous = $fact->supersedesFactId === null ? null : ($oldFacts[$fact->supersedesFactId] ?? null);
        if (($fact->supersedesFactId !== null && $previous === null)
            || $fact->version !== ($previous?->version ?? 0) + 1
            || ($previous !== null && (! in_array($previous->origin, ['user_input', 'user_assumption'], true) || $previous->nativeNumericProofs !== []))
            || ($previous !== null && [$previous->organizationId, $previous->projectId, $previous->sessionId, $previous->entityId, $previous->sourceVersion, $previous->type]
                !== [$fact->organizationId, $fact->projectId, $fact->sessionId, $fact->entityId, $fact->sourceVersion, $fact->type])) {
            return false;
        }
        foreach (['before' => [$previous, $before], 'after' => [$fact, $after]] as $side => [$record, $snapshot]) {
            if ($record === null) {
                continue;
            }
            $proofs = array_column($snapshot->evidence, null, 'id');
            if ($record->evidenceIds === []) {
                return false;
            }
            foreach ($record->evidenceIds as $id) {
                $proof = $proofs[$id] ?? null;
                if ($proof === null || $proof->sourceType !== 'user_input' || $proof->sourceVersion !== $record->sourceVersion
                    || [$proof->organizationId, $proof->projectId, $proof->sessionId] !== [$record->organizationId, $record->projectId, $record->sessionId]) {
                    return false;
                }
                $allowed[$side]['evidence'][$id] = true;
            }
            $allowed[$side]['facts'][$record->id] = $record;
        }

        return true;
    }

    private function writtenTokenStateCurrent(array $before, array $after, array $facts): bool
    {
        foreach ($facts as $fact) {
            $rows = array_values(array_filter($after['facts'], static fn (array $row): bool => $row['stable_key'] === $fact->id));
            if (count($rows) !== 1 || $rows[0]['version'] !== $fact->version || $rows[0]['projection_version'] !== $fact->version
                || $rows[0]['status'] !== 'confirmed' || in_array($rows[0]['id'], array_column($before['facts'], 'id'), true)
                || in_array($rows[0]['projection_id'], array_column($before['facts'], 'projection_id'), true)) {
                return false;
            }
            $bindings = array_values(array_filter($after['bindings'], static fn (array $row): bool => $row['fact_id'] === $rows[0]['id']));
            $ids = array_map(static fn (string $id): int => (int) substr($id, 9), $fact->evidenceIds);
            sort($ids, SORT_NUMERIC);
            $actualIds = array_column($bindings, 'evidence_id');
            sort($actualIds, SORT_NUMERIC);
            if ($ids !== $actualIds) {
                return false;
            }
            $evidence = array_column($after['evidence'], null, 'id');
            foreach ($bindings as $binding) {
                $proof = $evidence[$binding['evidence_id']] ?? null;
                if ($binding['source_version'] !== $fact->sourceVersion || $binding['evidence_source_version'] !== $fact->sourceVersion
                    || $binding['evidence_invalidation_version'] !== 0 || $proof === null || $proof['invalidation_version'] !== 0
                    || $proof['source_version'] !== $fact->sourceVersion || in_array($proof['id'], array_column($before['evidence'], 'id'), true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function remainingSnapshot(array $capture, array $allowed): string
    {
        $parts = [];
        foreach (['entities', 'facts', 'evidence', 'conflicts'] as $part) {
            $parts[$part] = array_column($capture['snapshot']->$part, null, 'id');
            $parts[$part] = array_diff_key($parts[$part], $allowed[$part] ?? []);
            ksort($parts[$part], SORT_STRING);
        }
        $parts['decisions'] = [];
        foreach ($capture['decisions'] as $decision) {
            if (! isset($allowed['facts'][$decision->selectedFactId ?? ''])) {
                $parts['decisions'][$decision->id] = $decision;
            }
        }
        ksort($parts['decisions'], SORT_STRING);

        return CanonicalPipelineJson::encode(json_decode(json_encode($parts, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR));
    }

    private function remainingTokenState(array $state, array $allowed): string
    {
        $factIds = [];
        foreach ($state['facts'] ?? [] as $fact) {
            if (isset($allowed['facts'][$fact['stable_key']])) {
                $factIds[$fact['id']] = true;
            }
        }
        foreach (['facts', 'bindings', 'evidence', 'decisions', 'entities'] as $part) {
            $state[$part] = array_values(array_filter($state[$part], static fn (array $record): bool => ! match ($part) {
                'facts' => isset($allowed['facts'][$record['stable_key']]),
                'bindings' => isset($factIds[$record['fact_id']]),
                'evidence' => isset($allowed['evidence']['evidence:'.$record['id']]),
                'decisions' => isset($allowed['facts'][$record['selected_fact_id']]),
                'entities' => isset($allowed['entities'][$record['stable_key']]),
            }));
        }

        return CanonicalPipelineJson::encode($state);
    }

    private function sameScenarioAfterRefresh(array $selected, array $materialized, string $beforeToken, string $afterToken): bool
    {
        $expected = $selected;
        foreach ($expected['scenario']['positions'] as $index => &$position) {
            $basis = $position['quantity_basis'] ?? null;
            if (! is_array($basis) || ! isset($basis['model_version'])) {
                continue;
            }
            $fresh = $materialized['scenario']['positions'][$index]['quantity_basis'] ?? null;
            if (! is_array($fresh) || $basis['model_version'] !== $beforeToken || ($fresh['model_version'] ?? null) !== $afterToken
                || ! is_array($basis['formula_inputs']['operands'] ?? null) || ! array_is_list($basis['formula_inputs']['operands'])
                || count($basis['formula_inputs']['operands']) !== count($fresh['formula_inputs']['operands'] ?? [])) {
                return false;
            }
            $position['quantity_basis']['model_version'] = $afterToken;
            $oldIdentities = [$basis['formula_inputs']['snapshot_identity'] ?? null, ...array_column($basis['formula_inputs']['operands'], 'snapshot_identity')];
            $newIdentities = [$fresh['formula_inputs']['snapshot_identity'] ?? null, ...array_column($fresh['formula_inputs']['operands'], 'snapshot_identity')];
            if (count($oldIdentities) !== count($basis['formula_inputs']['operands']) + 1 || count($oldIdentities) !== count($newIdentities)) {
                return false;
            }
            foreach ($oldIdentities as $identityIndex => $identity) {
                $replacement = $newIdentities[$identityIndex];
                $identityKeys = ['input_fingerprint', 'artifact_hash', 'technology_run_id', 'technology_input_fingerprint', 'technology_source_version',
                    'completeness_run_id', 'completeness_input_fingerprint', 'completeness_source_version', 'catalog_version', 'catalog_hash', 'rule_version', 'rule_hash'];
                if (! is_array($identity) || ! is_array($replacement) || ($identity['input_fingerprint'] ?? null) !== $beforeToken
                    || ($replacement['input_fingerprint'] ?? null) !== $afterToken
                    || array_diff($identityKeys, array_keys($identity)) !== [] || array_diff(array_keys($identity), $identityKeys) !== []
                    || ! is_string($identity['artifact_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $identity['artifact_hash']) !== 1
                    || ! is_string($replacement['artifact_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $replacement['artifact_hash']) !== 1
                    || CanonicalPipelineJson::encode([...$identity, 'input_fingerprint' => $afterToken, 'artifact_hash' => $replacement['artifact_hash']])
                        !== CanonicalPipelineJson::encode($replacement)) {
                    return false;
                }
                if ($identityIndex === 0) {
                    $position['quantity_basis']['formula_inputs']['snapshot_identity'] = $replacement;
                } else {
                    $position['quantity_basis']['formula_inputs']['operands'][$identityIndex - 1]['snapshot_identity'] = $replacement;
                }
            }
        }
        unset($position);
        $expected['scenario_hash'] = $materialized['scenario_hash'];

        return CanonicalPipelineJson::encode($expected) === CanonicalPipelineJson::encode($materialized);
    }
}
