<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;

final readonly class SaveManualProjectFacts
{
    public function __construct(
        private DatabaseManager $database,
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
        private EstimateGenerationActionAuthorization $authorization,
        private CurrentProjectDerivedQuantityService $quantities,
    ) {}

    public function handle(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId, array $input): array
    {
        return $this->database->connection()->transaction(function () use ($actor, $projectId, $sessionId, $expectedVersion, $requestId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload ?? [];
            $fingerprint = hash('sha256', json_encode([$actor->id, $input], JSON_THROW_ON_ERROR));
            $receipts = $payload['manual_fact_receipts'] ?? [];
            if (isset($receipts[$requestId])) {
                if (! hash_equals($fingerprint, $receipts[$requestId]['fingerprint'])) {
                    throw ValidationException::withMessages(['request_id' => 'Этот идентификатор уже использован для другого изменения.']);
                }

                return $receipts[$requestId]['result'];
            }
            if ($session->state_version !== $expectedVersion || $session->status->isTerminal()
                || in_array($session->status, [EstimateGenerationStatus::ProcessingDocuments, EstimateGenerationStatus::Generating, EstimateGenerationStatus::Applying], true)) {
                throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
            }
            if (count($receipts) >= 256) {
                throw ValidationException::withMessages(['entities' => 'Достигнут предел изменений этой оценки. Создайте новую сессию.']);
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $sourceVersion = 'sha256:'.hash('sha256', 'manual-facts:v1:'.$sessionId);
            $previousEntities = $this->models->snapshot(...[...$scope, 10001])->entities;
            $active = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            if (count($active->facts) > 10000) {
                throw ValidationException::withMessages(['entities' => 'Разделите состав: предел фактов сессии превышен.']);
            }
            $activeEntities = array_column($active->entities, null, 'id');
            $descriptionProposals = null;
            $descriptionReasons = [];
            $inventoryDocumentVersions = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::currentDocumentVersions($active);
            $inventorySnapshot = $active;
            $relationFactIds = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay::factIds($active);
            if ($relationFactIds !== []) {
                $inventorySnapshot = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryRelationOverlay::apply($active,
                    $this->models->decisionsForSelectedFacts(...[...$scope, $relationFactIds]), $inventoryDocumentVersions);
            }
            $entities = [];
            $byKey = [];
            $seenIds = [];
            foreach ($input['entities'] as $index => $row) {
                $key = $row['key'];
                if (isset($byKey[$key])) {
                    throw ValidationException::withMessages(['entities.'.$index.'.key' => 'Ключ объекта повторяется.']);
                }
                $id = 'entity:'.hash('sha256', json_encode([$sessionId, 'manual', $row['type'], $key, $row['floor'] ?? null, $row['zone'] ?? null], JSON_THROW_ON_ERROR));
                if (isset($row['existing_entity_id'])) {
                    $existing = $activeEntities[$row['existing_entity_id']] ?? null;
                    if ($existing === null || ($existing->attributes['semantic_type'] ?? $existing->attributes['properties']['semantic_type'] ?? $existing->type) !== $row['type']
                        || (($row['parameters'] ?? []) === [] && ($row['coverage'] ?? []) === [] && ! isset($row['parent_choice']))
                        || array_intersect(['name', 'floor', 'zone', 'parent_key'], array_keys($row)) !== []) {
                        throw ValidationException::withMessages(['entities.'.$index.'.existing_entity_id' => trans_message('estimate_generation.geometry_inventory.entity_invalid')]);
                    }
                    $id = $existing->id;
                    $entities[$id] = $existing;
                }
                if (isset($seenIds[$id])) {
                    throw ValidationException::withMessages(['entities.'.$index.'.key' => 'Объект повторяется в этом наборе.']);
                }
                $seenIds[$id] = true;
                $byKey[$key] = $id;
            }
            foreach ($input['entities'] as $index => $row) {
                $id = $byKey[$row['key']];
                if (isset($row['existing_entity_id'])) {
                    continue;
                }
                $type = $row['type'];
                $attributes = ['semantic_type' => $type, 'identity' => ['manual_key' => $row['key']]];
                foreach (['floor', 'zone'] as $field) {
                    if (isset($row[$field])) {
                        $attributes[$field.'_id'] = $row[$field];
                    }
                }
                if (isset($row['parent_key'])) {
                    $parent = $byKey[$row['parent_key']] ?? null;
                    if ($parent === null || $parent === $id) {
                        throw ValidationException::withMessages(['entities.'.$index.'.parent_key' => 'Укажите родительский объект из этого набора.']);
                    }
                    $parentRow = array_values(array_filter($input['entities'], static fn (array $candidate): bool => $candidate['key'] === $row['parent_key']))[0];
                    $parentType = match ($type) {
                        'wall' => 'room', 'opening' => 'wall', 'roof_facet', 'roof_opening' => 'roof', default => null
                    };
                    if ($parentType === null || $parentRow['type'] !== $parentType
                        || ($parentRow['floor'] ?? null) !== ($row['floor'] ?? null) || ($parentRow['zone'] ?? null) !== ($row['zone'] ?? null)) {
                        throw ValidationException::withMessages(['entities.'.$index.'.parent_key' => 'Проверьте тип, этаж и зону родительского объекта.']);
                    }
                    $attributes[match ($type) {
                        'wall' => 'room_id', 'opening' => 'wall_id', default => 'roof_id'
                    }] = $parent;
                } elseif (in_array($type, ['opening', 'roof_facet', 'roof_opening'], true)) {
                    throw ValidationException::withMessages(['entities.'.$index.'.parent_key' => 'Для проёма или грани требуется родительский объект.']);
                }
                $physicalType = in_array($type, Entity::TYPES, true) ? $type : 'quantity';
                if (in_array($type, FactVocabulary::engineeringTypes(), true)) {
                    $physicalType = 'equipment';
                    $attributes = ['name' => $row['name'] ?? $row['key'], 'equipment_code' => 'manual:'.substr(hash('sha256', $id), 0, 32),
                        'properties' => $attributes];
                }
                $entities[$id] = new Entity($id, ...[...$scope, $sourceVersion, $physicalType, $id, $attributes]);
            }
            $facts = $proofs = [];
            foreach ($input['entities'] as $index => $row) {
                $entity = $entities[$byKey[$row['key']]];
                $sourceVersion = $entity->sourceVersion;
                $parameters = [];
                if (isset($row['parent_choice'])) {
                    if (! config('estimate-generation.universal_enabled', false) || ($payload['evaluation_mode'] ?? null) !== 'universal'
                        || ! isset($row['existing_entity_id']) || count($input['entities']) !== 1
                        || ($row['parameters'] ?? []) !== [] || ($row['coverage'] ?? []) !== []) {
                        throw ValidationException::withMessages(['entities' => trans_message('estimate_generation.geometry_inventory.relation_invalid')]);
                    }
                    [$fact, $proof] = $this->parentChoice($scope, $entity, $row['parent_choice'], $inventorySnapshot, $inventoryDocumentVersions, $requestId);
                    $facts[] = $fact;
                    $proofs[$proof->id] = $proof;
                }
                if (array_key_exists('name', $row) || (($row['parameters'] ?? []) === [] && ($row['coverage'] ?? []) === [] && ! isset($row['parent_choice']))) {
                    $name = $row['name'] ?? $row['key'];
                    if (! is_string($name) || trim($name) === '' || mb_strlen($name) > 160) {
                        throw ValidationException::withMessages(['entities.'.$index.'.name' => 'Введите название объекта, не более 160 символов.']);
                    }
                    $factId = 'fact:'.hash('sha256', $requestId.'|'.$entity->id.'|entity_identity');
                    $identity = ['answer_kind' => 'entity_identity', 'entity_id' => $entity->id, 'entity_type' => $row['type'],
                        'name' => trim($name), 'identity_confirmed' => true, 'decision_id' => 'decision:'.hash('sha256', $requestId.'|'.$factId),
                        'reason' => 'Пользователь подтвердил ручные исходные данные'];
                    $choiceHash = hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($identity));
                    $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
                        'input:'.$sessionId, $sourceVersion, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.manual_facts'],
                        ['fact_key' => 'evaluation_choice', 'fact_value' => $choiceHash], 0.0, EvidenceProducer::UserInputNormalizer->value,
                        'sha256:'.hash('sha256', 'manual-entity-identity:v1')]));
                    $evidenceId = 'evidence:'.$node->id;
                    $proofs[$evidenceId] = new Evidence($evidenceId, ...[...$scope, $sourceVersion, 'input:'.$sessionId, 'user_input',
                        null, null, 'input_payload.manual_facts', EvidenceProducer::UserInputNormalizer->value, 'input:'.$sessionId, $choiceHash]);
                    $facts[] = $this->fact($scope, $sourceVersion, $entity, 'entity_identity', $identity, null, 'user_input', [$evidenceId], $requestId);
                }
                foreach ($row['parameters'] ?? [] as $parameter => $entry) {
                    $parameter = FactVocabulary::parameter($parameter);
                    $group = $parameter === 'equipment_count' ? 'count' : (in_array($parameter, ['area', 'plan_area'], true) ? 'area' : 'length');
                    $factor = FactVocabulary::factor($group, $entry['unit']);
                    if (! FactVocabulary::supports($row['type'], $parameter) || $factor === null || isset($parameters[$parameter])) {
                        throw ValidationException::withMessages(['entities.'.$index.'.parameters' => 'Параметр, единица или повторение параметра не поддерживается.']);
                    }
                    $parameters[$parameter] = true;
                    try {
                        $number = (string) BigDecimal::of((string) $entry['value']);
                    } catch (\Throwable) {
                        throw ValidationException::withMessages(['entities.'.$index.'.parameters' => 'Введите корректное число.']);
                    }
                    if (! CanonicalSourceDecimal::isNonNegative($number) || ($parameter !== 'slope_rise' && BigDecimal::of($number)->isZero())) {
                        throw ValidationException::withMessages(['entities.'.$index.'.parameters' => 'Размер должен быть положительным, не более четырёх знаков после запятой.']);
                    }
                    if (in_array($row['type'], FactVocabulary::engineeringTypes(), true)
                        && FactVocabulary::engineeringObservation($row['type'].':input', $parameter, $number, $entry['unit']) === null) {
                        throw ValidationException::withMessages(['entities.'.$index.'.parameters' => 'Проверьте единицу и значение: количество оборудования должно быть целым.']);
                    }
                    $basis = $entry['basis'];
                    $descriptionProposal = null;
                    if (isset($entry['description_proposal'])) {
                        if (! config('estimate-generation.universal_enabled', false) || ($payload['evaluation_mode'] ?? null) !== 'universal'
                            || ! in_array($basis, ['input', 'assumption'], true) || ! is_array($entry['description_proposal'])) {
                            throw ValidationException::withMessages(['entities' => 'Предложение из описания требует отдельного подтверждения введённого значения или допущения.']);
                        }
                        if ($descriptionProposals === null) {
                            $description = (string) ($payload['description'] ?? '');
                            $descriptionSources = \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DescriptionNumericProposals::sourceFacts($active, $description);
                            $descriptionDecisions = $descriptionSources === [] ? [] : $this->models->decisionsForSelectedFacts(...[...$scope, array_column($descriptionSources, 'id')]);
                            $descriptionProposals = array_column(\App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DescriptionNumericProposals::fromSnapshot($active, $description, $descriptionDecisions), null, 'proposal_id');
                        }
                        $reference = $entry['description_proposal'];
                        $descriptionProposal = $descriptionProposals[$reference['proposal_id'] ?? ''] ?? null;
                        if ($descriptionProposal === null || count($reference) !== 4 || $descriptionProposal['parameter'] !== $parameter
                            || $descriptionProposal['unit'] !== $entry['unit'] || ! BigDecimal::of($descriptionProposal['value'])->isEqualTo($number)
                            || ($reference['source_fact_id'] ?? null) !== $descriptionProposal['source_fact_id']
                            || ($reference['source_fact_version'] ?? null) !== $descriptionProposal['source_fact_version']
                            || ($reference['source_value_hash'] ?? null) !== $descriptionProposal['source_value_hash']) {
                            throw ValidationException::withMessages(['entities' => 'Предложение или описание изменились. Загрузите актуальный источник; изменённое число введите отдельно.']);
                        }
                    }
                    $measurement = $basis === 'measurement';
                    $nativeReference = ($descriptionProposal === null ? 'input_payload.manual_facts_' : 'input_payload.description_')
                        .hash('sha256', $entity->id.'|'.$parameter);
                    $node = $this->evidence->insertOrGet(new EvidenceData(...[
                        ...$scope, $measurement ? EvidenceType::Measured : EvidenceType::SourceFact, EvidenceSourceType::UserInput, 'input:'.$sessionId, $sourceVersion,
                        ['source_key' => 'source:'.hash('sha256', $requestId.'|'.$entity->id.'|'.$parameter.'|'.$basis), 'native_reference' => $nativeReference],
                        $measurement ? ['quantity' => BigDecimal::of($number)->multipliedBy($factor)->toFloat(), 'unit' => $group === 'count' ? 'pcs' : ($group === 'area' ? 'm2' : 'm'), 'method' => 'user_confirmed']
                            : ['fact_key' => 'quantity', 'fact_value' => $number], 0.0, EvidenceProducer::UserInputNormalizer->value,
                        'sha256:'.hash('sha256', 'manual-facts:v1:'.$basis),
                    ]));
                    $evidenceId = 'evidence:'.$node->id;
                    $proofs[$evidenceId] = new Evidence($evidenceId, ...[...$scope, $sourceVersion, 'input:'.$sessionId, 'user_input', null, null, $nativeReference]);
                    $newFact = $this->fact($scope, $sourceVersion, $entity, $parameter, $number, $entry['unit'], $basis === 'assumption' ? 'user_assumption' : 'user_input', [$evidenceId], $requestId);
                    $facts[] = $newFact;
                    if ($descriptionProposal !== null) {
                        $descriptionReasons[$newFact->id] = 'Пользователь проверил число из описания и выбрал объект: '
                            .\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode([
                                ...$descriptionProposal, 'target_entity_id' => $entity->id, 'basis' => $basis,
                            ]);
                        if (strlen($descriptionReasons[$newFact->id]) > 1000) {
                            throw ValidationException::withMessages(['entities' => 'Основание слишком длинное для истории решений. Введите размер отдельно.']);
                        }
                    }
                }
                foreach ($row['coverage'] ?? [] as $relation => $status) {
                    $inventory = null;
                    if (isset($row['existing_entity_id'])) {
                        try {
                            $inventory = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::capture($inventorySnapshot, $entity->id, $relation, $inventoryDocumentVersions);
                        } catch (\InvalidArgumentException) {
                            throw ValidationException::withMessages(['entities.'.$index.'.coverage' => trans_message('estimate_generation.geometry_inventory.entity_invalid')]);
                        }
                        $pin = $row['coverage_fingerprints'][$relation] ?? null;
                        if (! is_string($pin) || ! hash_equals($inventory['fingerprint'], $pin)) {
                            throw ValidationException::withMessages(['entities.'.$index.'.coverage' => trans_message('estimate_generation.geometry_inventory.changed')]);
                        }
                        if (in_array($status, ['covered_empty', 'covered_with_entities'], true) && $inventory['unassigned_ids'] !== []) {
                            throw ValidationException::withMessages(['entities.'.$index.'.coverage' => trans_message('estimate_generation.geometry_inventory.unassigned')]);
                        }
                    }
                    $expected = match ($relation) {
                        'room_walls' => ['room', 'wall', 'room_id'], 'wall_openings' => ['wall', 'opening', 'wall_id'], 'roof_facets' => ['roof', 'roof_facet', 'roof_id'], 'roof_openings' => ['roof', 'roof_opening', 'roof_id'], default => null
                    };
                    if ($expected === null || $row['type'] !== $expected[0]) {
                        throw ValidationException::withMessages(['entities.'.$index.'.coverage' => 'Покрытие не относится к этому объекту.']);
                    }
                    $coverageEntities = $entities;
                    foreach ($previousEntities as $previous) {
                        $coverageEntities[$previous->id] ??= $previous;
                    }
                    $count = count(array_filter($coverageEntities, static fn (Entity $child): bool => ($child->attributes['semantic_type'] ?? null) === $expected[1] && ($child->attributes[$expected[2]] ?? null) === $entity->id));
                    if ($inventory !== null) {
                        $count = count($inventory['member_ids']);
                    }
                    if (($status === 'covered_empty' && $count !== 0) || ($status === 'covered_with_entities' && $count === 0)) {
                        throw ValidationException::withMessages(['entities.'.$index.'.coverage' => 'Подтверждение не соответствует указанным элементам.']);
                    }
                    $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput, 'input:'.$sessionId, $sourceVersion,
                        ['source_key' => 'source:'.hash('sha256', $requestId.'|'.$entity->id.'|'.$relation), 'native_reference' => 'input_payload.manual_facts'], ['fact_key' => 'quantity', 'fact_value' => $count], 0.0,
                        EvidenceProducer::UserInputNormalizer->value, 'sha256:'.hash('sha256', 'manual-coverage:v1')]));
                    $evidenceId = 'evidence:'.$node->id;
                    $proofs[$evidenceId] = new Evidence($evidenceId, ...[...$scope, $sourceVersion, 'input:'.$sessionId, 'user_input', null, null, 'input_payload.manual_facts']);
                    $facts[] = $this->fact($scope, $sourceVersion, $entity, 'geometry_coverage_'.$relation,
                        ['relation' => $relation, 'status' => $status, 'entity_count' => $count, 'representation' => ['type' => 'manual', 'id' => $entity->id,
                            'source_artifact_id' => 'input:'.$sessionId, 'source_version' => $sourceVersion,
                            ...($inventory === null ? [] : ['inventory_contract' => 'geometry-inventory:v1', 'inventory_fingerprint' => $inventory['fingerprint'],
                                'member_ids' => $inventory['member_ids']])]], null, 'user_input', [$evidenceId], $requestId);
                }
            }
            // Document objects retain their source version; each repository batch has one exact version.
            $groups = [];
            foreach ($entities as $entity) {
                $groups[$entity->sourceVersion]['entities'][] = $entity;
            }
            foreach ($facts as $fact) {
                $groups[$fact->sourceVersion]['facts'][] = $fact;
            }
            foreach ($proofs as $proof) {
                $groups[$proof->sourceVersion]['evidence'][] = $proof;
            }
            foreach ($groups as $group) {
                $this->models->saveSourceModel($group['entities'], $group['facts'] ?? [], $group['evidence'] ?? []);
            }
            foreach ($facts as $fact) {
                $this->models->applyDecision(new Decision('decision:'.hash('sha256', $requestId.'|'.$fact->id), ...[...$scope, $fact->sourceVersion,
                    'fact', $fact->supersedesFactId ?? $fact->id, $fact->id, 'user', (string) $actor->id,
                    str_starts_with($fact->type, 'geometry_parent_') ? $fact->value['reason'] : ($descriptionReasons[$fact->id] ?? 'Пользователь подтвердил ручные исходные данные'), $fact->version, $fact->evidenceIds]), $fact);
            }
            $preview = $this->quantities->previewInput(...$scope);
            $result = ['request_id' => $requestId, 'state_version' => $expectedVersion + 1, 'fact_ids' => array_column($facts, 'id'),
                'quantities' => array_map(static fn ($quantity): array => $quantity->toArray(), $preview['quantities']), 'warnings' => $preview['warnings'], 'cost_units' => '0'];
            $receipts[$requestId] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'input' => $input, 'result' => $result];
            $payload['manual_fact_receipts'] = $receipts;
            $payload['manual_input_revision'] = ($payload['manual_input_revision'] ?? 0) + 1;
            $payload['input_confirmed'] = false;
            unset($payload['confirmed_evaluation']);
            $session->fill(['input_payload' => $payload, 'state_version' => $expectedVersion + 1,
                'status' => EstimateGenerationStatus::InputReviewRequired, 'processing_stage' => 'input_review_required'])->save();

            return $result;
        }, 3);
    }

    private function parentChoice(array $scope, Entity $child, array $input, \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot $snapshot,
        array $documentAnchors, string $requestId): array
    {
        $relation = $input['relation'] ?? null;
        $definition = is_string($relation) ? (\App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::RELATIONS[$relation] ?? null) : null;
        $current = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::entities($snapshot, $documentAnchors);
        $effectiveChild = $current[$child->id] ?? null;
        $parentId = $input['parent_entity_id'] ?? null;
        $parent = is_string($parentId) ? ($current[$parentId] ?? null) : null;
        $reason = $input['reason'] ?? null;
        if ($definition === null || $effectiveChild === null || $parent === null || $child->id === $parent->id
            || \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::type($child) !== $definition[1]
            || \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::type($parent) !== $definition[0]
            || ! is_string($reason) || trim($reason) === '' || strlen(trim($reason)) > 1000) {
            throw ValidationException::withMessages(['entities' => trans_message('estimate_generation.geometry_inventory.relation_invalid')]);
        }
        $inventory = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::capture($snapshot, $parent->id, $relation, $documentAnchors);
        if (($input['inventory_fingerprint'] ?? null) !== $inventory['fingerprint']
            || ($input['child_fingerprint'] ?? null) !== \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::entityFingerprint($effectiveChild, $documentAnchors)) {
            throw ValidationException::withMessages(['entities' => trans_message('estimate_generation.geometry_inventory.changed')]);
        }
        $parameter = 'geometry_parent_'.$relation;
        $factId = 'fact:'.hash('sha256', $requestId.'|'.$child->id.'|'.$parameter);
        $value = ['relation' => $relation, 'parent_entity_id' => $parent->id, 'parent_source_version' => $parent->sourceVersion,
            'inventory_fingerprint' => $inventory['fingerprint'], 'child_fingerprint' => $input['child_fingerprint'],
            'decision_id' => 'decision:'.hash('sha256', $requestId.'|'.$factId), 'reason' => trim($reason)];
        $hash = hash('sha256', \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($value));
        $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
            'input:'.$scope[2], $child->sourceVersion, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.manual_facts'],
            ['fact_key' => 'evaluation_choice', 'fact_value' => $hash], 0.0, EvidenceProducer::UserInputNormalizer->value,
            'sha256:'.hash('sha256', 'geometry-parent-choice:v1')]));
        $proof = new Evidence('evidence:'.$node->id, ...[...$scope, $child->sourceVersion, 'input:'.$scope[2], 'user_input', null, null,
            'input_payload.manual_facts', EvidenceProducer::UserInputNormalizer->value, 'input:'.$scope[2], $hash]);
        $fact = $this->fact($scope, $child->sourceVersion, $child, $parameter, $value, null, 'user_input', [$proof->id], $requestId);

        return [$fact, $proof];
    }

    private function fact(array $scope, string $version, Entity $entity, string $parameter, mixed $value, ?string $unit, string $origin, array $evidence, string $requestId): Fact
    {
        $previous = null;
        foreach ($this->models->currentFacts(...[...$scope, $entity->id]) as $fact) {
            if (FactVocabulary::parameter($fact->type) === $parameter && $fact->sourceVersion === $version) {
                if ($previous !== null) {
                    throw ValidationException::withMessages(['entities' => 'Есть противоречивые значения параметра. Сначала подтвердите его источник.']);
                }
                $previous = $fact;
            }
        }

        return new Fact('fact:'.hash('sha256', $requestId.'|'.$entity->id.'|'.$parameter), ...[...$scope, $version, $entity->id,
            $previous?->type ?? $parameter, $value, $unit, 0.0, $origin, 'confirmed', $evidence, ($previous?->version ?? 0) + 1, $previous?->id]);
    }
}
