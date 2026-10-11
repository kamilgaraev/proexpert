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
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class UserEvaluationChoiceWriter
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private EvidenceRepository $evidence) {}

    public function write(User $actor, EstimateGenerationSession $session, string $anchorKey, string $type, array $value,
        string $reason, string $requestId): array
    {
        $this->authorization->authorize($actor, $session, 'estimate_generation.review');
        if (! Str::isUuid($requestId) || ! in_array($type, ['evaluation_price', 'evaluation_scope', 'evaluation_technology', 'evaluation_scenario', 'evaluation_answer', 'evaluation_currency'], true)
            || $anchorKey === '' || strlen($anchorKey) > 191 || trim($reason) === '' || strlen($reason) > 1000) {
            throw new InvalidArgumentException('evaluation_choice_contract_invalid');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', $scope));
        $entityId = 'entity:'.hash('sha256', $version.'|'.$anchorKey);
        // This is a ledger anchor for a choice. It has no geometric value or dimensions.
        $entity = new Entity($entityId, ...[...$scope, $version, 'quantity', $entityId,
            ['semantic_type' => 'quantity', 'identity' => ['evaluation_anchor' => $anchorKey]]]);
        $previous = null;
        foreach ($this->models->currentFacts(...[...$scope, $entityId]) as $fact) {
            if ($fact->type === $type && $fact->sourceVersion === $version) {
                $previous = $fact;
            }
        }
        $factId = 'fact:'.hash('sha256', $requestId.'|'.$entityId.'|'.$type);
        $decisionId = 'decision:'.hash('sha256', $requestId.'|'.$factId);
        $value = [...$value, 'decision_id' => $decisionId, 'reason' => $reason];
        $hash = hash('sha256', CanonicalPipelineJson::encode($value));

        return $this->evidence->transaction($scope[0], $scope[2], function () use ($scope, $version, $entity, $entityId, $value, $hash,
            $factId, $decisionId, $type, $actor, $reason, $previous): array {
            $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
                'input:'.$scope[2], $version, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.evaluation_choices'],
                ['fact_key' => 'evaluation_choice', 'fact_value' => $hash], 0.0, EvidenceProducer::UserInputNormalizer->value,
                'sha256:'.hash('sha256', 'evaluation-choices:v1')]));
            $proofId = 'evidence:'.$node->id;
            $proof = new Evidence($proofId, ...[...$scope, $version, 'input:'.$scope[2], 'user_input', null, null, 'input_payload.evaluation_choices',
                EvidenceProducer::UserInputNormalizer->value, 'input:'.$scope[2], $hash]);
            $fact = new Fact($factId, ...[...$scope, $version, $entityId, $type, $value, null, 0.0, 'user_input', 'confirmed', [$proofId],
                ($previous?->version ?? 0) + 1, $previous?->id]);
            $decision = new Decision($decisionId, ...[...$scope, $version, 'fact', $previous?->id ?? $factId, $factId, 'user',
                (string) $actor->id, $reason, $fact->version, [$proofId]]);
            $this->models->saveSourceModel([$entity], [$fact], [$proof]);
            $this->models->applyDecision($decision, $fact);

            return ['fact_id' => $factId, 'decision_id' => $decisionId, 'entity_id' => $entityId];
        });
    }
}
