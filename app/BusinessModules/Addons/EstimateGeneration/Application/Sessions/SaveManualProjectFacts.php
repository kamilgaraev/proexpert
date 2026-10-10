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
            $entities = [];
            $byKey = [];
            foreach ($input['entities'] as $index => $row) {
                $key = $row['key'];
                if (isset($byKey[$key])) {
                    throw ValidationException::withMessages(['entities.'.$index.'.key' => 'Ключ объекта повторяется.']);
                }
                $id = 'entity:'.hash('sha256', json_encode([$sessionId, 'manual', $row['type'], $key, $row['floor'] ?? null, $row['zone'] ?? null], JSON_THROW_ON_ERROR));
                $byKey[$key] = $id;
            }
            foreach ($input['entities'] as $index => $row) {
                $id = $byKey[$row['key']];
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
                $entities[$id] = new Entity($id, ...[...$scope, $sourceVersion, in_array($type, Entity::TYPES, true) ? $type : 'quantity', $id, $attributes]);
            }
            $facts = $proofs = [];
            foreach ($input['entities'] as $index => $row) {
                $entity = $entities[$byKey[$row['key']]];
                $parameters = [];
                foreach ($row['parameters'] ?? [] as $parameter => $entry) {
                    $parameter = FactVocabulary::parameter($parameter);
                    $group = in_array($parameter, ['area', 'plan_area'], true) ? 'area' : 'length';
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
                    $basis = $entry['basis'];
                    $measurement = $basis === 'measurement';
                    $node = $this->evidence->insertOrGet(new EvidenceData(...[
                        ...$scope, $measurement ? EvidenceType::Measured : EvidenceType::SourceFact, EvidenceSourceType::UserInput, 'input:'.$sessionId, $sourceVersion,
                        ['source_key' => 'source:'.hash('sha256', $requestId.'|'.$entity->id.'|'.$parameter.'|'.$basis), 'native_reference' => 'input_payload.manual_facts'],
                        $measurement ? ['quantity' => BigDecimal::of($number)->multipliedBy($factor)->toFloat(), 'unit' => $group === 'area' ? 'm2' : 'm', 'method' => 'user_confirmed']
                            : ['fact_key' => 'quantity', 'fact_value' => $number], 0.0, EvidenceProducer::UserInputNormalizer->value,
                        'sha256:'.hash('sha256', 'manual-facts:v1:'.$basis),
                    ]));
                    $evidenceId = 'evidence:'.$node->id;
                    $proofs[$evidenceId] = new Evidence($evidenceId, ...[...$scope, $sourceVersion, 'input:'.$sessionId, 'user_input', null, null, 'input_payload.manual_facts']);
                    $facts[] = $this->fact($scope, $sourceVersion, $entity, $parameter, $number, $entry['unit'], $basis === 'assumption' ? 'user_assumption' : 'user_input', [$evidenceId], $requestId);
                }
                foreach ($row['coverage'] ?? [] as $relation => $status) {
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
                            'source_artifact_id' => 'input:'.$sessionId, 'source_version' => $sourceVersion]], null, 'user_input', [$evidenceId], $requestId);
                }
            }
            $this->models->saveSourceModel(array_values($entities), $facts, array_values($proofs));
            foreach ($facts as $fact) {
                $this->models->applyDecision(new Decision('decision:'.hash('sha256', $requestId.'|'.$fact->id), ...[...$scope, $sourceVersion,
                    'fact', $fact->supersedesFactId ?? $fact->id, $fact->id, 'user', (string) $actor->id, 'Пользователь подтвердил ручные исходные данные', $fact->version, $fact->evidenceIds]), $fact);
            }
            $preview = $this->quantities->previewInput(...$scope);
            $result = ['request_id' => $requestId, 'state_version' => $expectedVersion + 1, 'fact_ids' => array_column($facts, 'id'),
                'quantities' => array_map(static fn ($quantity): array => $quantity->toArray(), $preview['quantities']), 'warnings' => $preview['warnings'], 'cost_units' => '0'];
            $receipts[$requestId] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'input' => $input, 'result' => $result];
            $payload['manual_fact_receipts'] = $receipts;
            $payload['manual_input_revision'] = ($payload['manual_input_revision'] ?? 0) + 1;
            $payload['input_confirmed'] = false;
            $session->fill(['input_payload' => $payload, 'state_version' => $expectedVersion + 1,
                'status' => EstimateGenerationStatus::InputReviewRequired, 'processing_stage' => 'input_review_required'])->save();

            return $result;
        }, 3);
    }

    private function fact(array $scope, string $version, Entity $entity, string $parameter, mixed $value, ?string $unit, string $origin, array $evidence, string $requestId): Fact
    {
        $previous = null;
        foreach ($this->models->currentFacts(...[...$scope, $entity->id]) as $fact) {
            if ($fact->type === $parameter && $fact->sourceVersion === $version) {
                $previous = $fact;
                break;
            }
        }

        return new Fact('fact:'.hash('sha256', $requestId.'|'.$entity->id.'|'.$parameter), ...[...$scope, $version, $entity->id,
            $parameter, $value, $unit, 0.0, $origin, 'confirmed', $evidence, ($previous?->version ?? 0) + 1, $previous?->id]);
    }
}
