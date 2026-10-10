<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceAttribute;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Brick\Math\BigDecimal;

final readonly class InitialProjectFactsWriter
{
    public function __construct(private ProjectModelRepository $models, private EvidenceRepository $evidence) {}

    public function write(EstimateGenerationSession $session, User $actor): void
    {
        $input = is_array($session->input_payload) ? $session->input_payload : [];
        $values = [];
        foreach (['area', 'floors', 'height'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if ((! is_string($value) && ! is_int($value) && ! is_float($value))
                || (is_float($value) && ! is_finite($value))) {
                throw new InitialProjectFactValidationFailed($field);
            }
            try {
                $decimal = BigDecimal::of((string) $value);
            } catch (\Throwable) {
                throw new InitialProjectFactValidationFailed($field);
            }
            if (! CanonicalSourceDecimal::isNonNegative((string) $decimal)
                || ($field === 'floors' && ($decimal->isLessThan(1) || $decimal->isGreaterThan(250)
                    || ! $decimal->isEqualTo($decimal->toScale(0, \Brick\Math\RoundingMode::DOWN))))) {
                throw new InitialProjectFactValidationFailed($field);
            }
            $values[$field] = (string) $decimal;
        }
        if ($values === []) {
            return;
        }
        $organizationId = (int) $session->organization_id;
        $projectId = (int) $session->project_id;
        $sessionId = (int) $session->id;
        $version = 'sha256:'.hash('sha256', json_encode(['initial-parameters:v1', $values], JSON_THROW_ON_ERROR));
        $this->evidence->transaction($organizationId, $sessionId, function () use ($organizationId, $projectId, $sessionId, $version, $values, $actor): void {
            $entities = [];
            $facts = [];
            $evidence = [];
            foreach ($values as $field => $value) {
                $entityId = 'entity:'.hash('sha256', 'initial:'.$sessionId.':'.$field);
                $factId = 'fact:'.hash('sha256', $entityId.'|'.$version.'|'.$field);
                $sourceKey = 'source:'.hash('sha256', 'initial:'.$sessionId.':'.$field.':'.(int) $actor->id);
                [$type, $unit, $attribute] = match ($field) {
                    'area' => ['area', 'm2', EvidenceAttribute::RoomArea],
                    'floors' => ['floor_count', 'pcs', EvidenceAttribute::FloorCount],
                    default => ['building_height', 'm', EvidenceAttribute::Quantity],
                };
                $node = $this->evidence->insertOrGet(new EvidenceData(
                    $organizationId, $projectId, $sessionId, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
                    'input:'.$sessionId, $version, ['source_key' => $sourceKey],
                    ['fact_key' => $attribute->value, 'fact_value' => $value, 'unit' => $unit], 0.0,
                    EvidenceProducer::UserInputNormalizer->value, 'sha256:'.hash('sha256', 'initial-parameters:v1'),
                ));
                $evidenceId = 'evidence:'.$node->id;
                $entities[] = new Entity($entityId, $organizationId, $projectId, $sessionId, $version,
                    $field === 'area' ? 'room' : 'dimension', $entityId,
                    $field === 'area' ? ['semantic_type' => 'room', 'document_role' => 'building_floor', 'identity' => ['input_field' => $field]]
                        : ['measurement_kind' => 'dimension_chain', 'identity' => ['input_field' => $field, 'parameter' => $type]]);
                $evidence[] = new Evidence($evidenceId, $organizationId, $projectId, $sessionId, $version,
                    'input:'.$sessionId, 'user_input', null, null, 'input_payload.'.$field);
                $facts[] = new Fact($factId, $organizationId, $projectId, $sessionId, $version, $entityId,
                    $type, $value, $unit, 0.0, 'user_input', 'confirmed', [$evidenceId]);
            }
            $this->models->saveSourceModel($entities, $facts, $evidence);
            foreach ($facts as $fact) {
                $this->models->applyDecision(new Decision('decision:'.hash('sha256', $fact->id.'|initial-input'),
                    $organizationId, $projectId, $sessionId, $version, 'fact', $fact->id, $fact->id,
                    'user', (string) $actor->id, 'Числовой параметр введён пользователем при создании оценки', 1, $fact->evidenceIds), $fact);
            }
        });
    }
}
