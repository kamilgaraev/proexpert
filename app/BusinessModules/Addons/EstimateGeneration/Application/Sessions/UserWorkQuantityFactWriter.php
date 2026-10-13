<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;

/** Uses the existing Fact/Evidence/Decision ledger; no separate geometry storage. */
final readonly class UserWorkQuantityFactWriter
{
    public function __construct(private ProjectModelRepository $models, private EvidenceRepository $evidence, private EstimateGenerationActionAuthorization $authorization) {}

    public function write(User $actor, EstimateGenerationSession $session, string $workKey, string $amount, string $unit, string $reason, string $requestId, bool $assumed = false, ?Entity $target = null): Fact
    {
        $this->authorization->authorize($actor, $session, 'estimate_generation.review');
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $version = 'sha256:'.hash('sha256', 'evaluation-quantities:v1|'.implode('|', $scope));
        $entityId = 'entity:'.hash('sha256', $version.'|'.$workKey);
        $entity = new Entity($entityId, ...[...$scope, $version, 'quantity', $entityId,
            ['semantic_type' => 'quantity', 'identity' => ['work_item_key' => $workKey]]]);
        $type = 'quantity';
        if ($target !== null) {
            if ([$target->organizationId, $target->projectId, $target->sessionId] !== $scope) {
                throw new \InvalidArgumentException('work_quantity_entity_scope_invalid');
            }
            $entity = $target;
            $entityId = $target->id;
            $version = $target->sourceVersion;
            // Different works of one asset can have different quantities and units.
            $type = 'work_quantity_'.substr(hash('sha256', $workKey), 0, 48);
        }
        $previous = null;
        foreach ($this->models->currentFacts(...[...$scope, $entityId]) as $fact) {
            if ($fact->type === $type && $fact->sourceVersion === $version) {
                $previous = $fact;
            }
        }
        $value = (string) \Brick\Math\BigDecimal::of($amount)->strippedOfTrailingZeros();
        if (! \App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal::isPositive($value)) {
            throw new \InvalidArgumentException('work_quantity_source_decimal_invalid');
        }
        $factId = 'fact:'.hash('sha256', $requestId.'|'.$entityId.'|'.$type);
        $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
            'input:'.$scope[2], $version, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.work_quantities'],
            ['fact_key' => 'quantity', 'fact_value' => $value], 0.0, EvidenceProducer::UserInputNormalizer->value, 'sha256:'.hash('sha256', 'work-quantity:v1')]));
        $proofId = 'evidence:'.$node->id;
        $proof = new Evidence($proofId, ...[...$scope, $version, 'input:'.$scope[2], 'user_input', null, null, 'input_payload.work_quantities',
            EvidenceProducer::UserInputNormalizer->value, 'input:'.$scope[2]]);
        $fact = new Fact($factId, ...[...$scope, $version, $entityId, $type, $value, $unit, 0.0, $assumed ? 'user_assumption' : 'user_input', 'confirmed', [$proofId],
            ($previous?->version ?? 0) + 1, $previous?->id]);
        $decision = new Decision('decision:'.hash('sha256', $requestId.'|'.$factId), ...[...$scope, $version, 'fact', $previous?->id ?? $factId,
            $factId, 'user', (string) $actor->id, $reason, $fact->version, [$proofId]]);
        $this->models->saveSourceModel([$entity], [$fact], [$proof]);
        $this->models->applyDecision($decision, $fact);

        return $fact;
    }
}
