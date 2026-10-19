<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\ProjectPlanningPipeline;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
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
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\PrerequisiteInputDefinition;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class UpdateEstimatePrerequisites
{
    public function __construct(
        private DatabaseManager $database,
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
        private EstimateGenerationActionAuthorization $authorization,
        private EstimatePrerequisiteBacklog $backlog,
        private ProjectPlanningPipeline $pipeline,
    ) {}

    public function refresh(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId): array
    {
        return $this->update($actor, $projectId, $sessionId, $expectedVersion, $requestId, 'refresh', []);
    }

    public function answer(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId, array $input): array
    {
        return $this->update($actor, $projectId, $sessionId, $expectedVersion, $requestId, 'answer', $input);
    }

    private function update(User $actor, int $projectId, int $sessionId, int $expectedVersion, string $requestId, string $action, array $input): array
    {
        if ($expectedVersion < 0 || ! Str::isUuid($requestId)) {
            throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.prerequisites.invalid_answer')]);
        }

        return $this->database->connection()->transaction(function () use ($actor, $projectId, $sessionId, $expectedVersion, $requestId, $action, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload ?? [];
            $receipts = $payload['prerequisite_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode([(int) $actor->id, $action, $expectedVersion, $input]));
            if (isset($receipts[$requestId])) {
                if (! hash_equals($receipts[$requestId]['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.prerequisites.idempotency_collision')]);
                }

                return $receipts[$requestId]['result'];
            }
            if ((int) $session->state_version !== $expectedVersion || ! EstimatePrerequisiteBacklog::editable($session)) {
                throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
            }
            if (count($receipts) >= 256) {
                throw ValidationException::withMessages(['request_id' => trans_message('estimate_generation.prerequisites.receipt_limit')]);
            }
            $result = ['request_id' => $requestId, 'action' => $action, 'state_version' => $expectedVersion + 1, 'cost_units' => '0'];
            if ($action === 'answer') {
                $result = [...$result, ...$this->saveAnswer($actor, $session, $requestId, $input)];
                $payload['manual_input_revision'] = ($payload['manual_input_revision'] ?? 0) + 1;
            }
            if ($action === 'answer' || $session->status !== EstimateGenerationStatus::Failed) {
                $session->fill(['status' => EstimateGenerationStatus::InputReviewRequired, 'processing_stage' => 'input_review_required']);
            }
            $payload['input_confirmed'] = false;
            unset($payload['confirmed_evaluation']);
            $receipts[$requestId] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id,
                'action' => $action, 'input' => $input, 'result' => $result];
            $payload['prerequisite_receipts'] = $receipts;
            $session->fill(['input_payload' => $payload, 'state_version' => $expectedVersion + 1])->save();
            $this->pipeline->refresh((int) $session->organization_id, $projectId, $sessionId, $requestId, 1, allowModelCalls: false);

            return $result;
        }, 3);
    }

    private function saveAnswer(User $actor, EstimateGenerationSession $session, string $requestId, array $input): array
    {
        if (array_diff(array_keys($input), ['finding_key', 'backlog_version', 'question_key', 'value', 'unit', 'basis', 'reason']) !== []
            || ! is_string($input['finding_key'] ?? null) || strlen($input['finding_key']) > 191
            || ! is_string($input['backlog_version'] ?? null) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $input['backlog_version']) !== 1
            || ! is_string($input['question_key'] ?? null) || preg_match('/\Aprerequisite:[a-f0-9]{64}\z/D', $input['question_key']) !== 1
            || ! CanonicalSourceDecimal::isPositive($input['value'] ?? null)
            || ! is_string($input['unit'] ?? null) || ! in_array($input['basis'] ?? null, ['input', 'measurement', 'assumption'], true)
            || ! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || strlen($input['reason']) > 1000
            || ! mb_check_encoding($input['reason'], 'UTF-8')) {
            throw ValidationException::withMessages(['answer' => trans_message('estimate_generation.prerequisites.invalid_answer')]);
        }
        $current = $this->backlog->current($session);
        $question = null;
        foreach ($current['groups'] as $group) {
            if ($group['finding_key'] === $input['finding_key'] && hash_equals($group['version'], $input['backlog_version'])) {
                foreach ($group['questions'] as $candidate) {
                    if ($candidate['key'] === $input['question_key'] && $candidate['can_answer']) {
                        $question = $candidate;
                        break 2;
                    }
                }
            }
        }
        if ($question === null) {
            throw new StaleEstimateGenerationState((int) $session->id, (int) $session->state_version);
        }
        $unit = $question['input_contract']['unit'];
        if ($input['unit'] !== $unit || ! PrerequisiteInputDefinition::accepts($question['input_fact_type'], $input['value'], $unit, $unit)) {
            throw ValidationException::withMessages(['answer' => trans_message('estimate_generation.prerequisites.invalid_answer')]);
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $target = $question['target'];
        $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        $previous = array_values(array_filter($snapshot->facts, static fn (Fact $fact): bool => $fact->entityId === $target['entity_id']
            && $fact->type === $question['input_fact_type']));
        if (count($previous) > 1 || ($previous !== [] && $previous[0]->sourceVersion !== $target['entity_source_version'])) {
            throw new StaleEstimateGenerationState((int) $session->id, (int) $session->state_version);
        }
        $number = (string) BigDecimal::of($input['value'])->strippedOfTrailingZeros();
        $measurement = $input['basis'] === 'measurement';
        $factId = 'fact:'.hash('sha256', 'prerequisite:v1:'.$session->id.'|'.$requestId);
        $nativeReference = 'input_payload.prerequisite_receipts';
        $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope,
            $measurement ? EvidenceType::Measured : EvidenceType::SourceFact, EvidenceSourceType::UserInput,
            'input:'.$session->id, $target['entity_source_version'],
            ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => $nativeReference],
            $measurement ? ['quantity' => $number, 'unit' => $unit === 'item' ? 'pcs' : $unit, 'method' => 'user_confirmed']
                : ['fact_key' => 'quantity', 'fact_value' => $number, 'unit' => $unit === 'item' ? 'pcs' : $unit],
            0.0, EvidenceProducer::UserInputNormalizer->value, 'sha256:'.hash('sha256', 'prerequisite-answer:v1:'.$input['basis']),
        ]));
        $proof = new Evidence('evidence:'.$node->id, ...[...$scope, $target['entity_source_version'],
            'input:'.$session->id, 'user_input', null, null, $nativeReference]);
        $prior = $previous[0] ?? null;
        $fact = new Fact($factId, ...[...$scope, $target['entity_source_version'], $target['entity_id'], $question['input_fact_type'],
            $number, $unit, 0.0, $input['basis'] === 'assumption' ? 'user_assumption' : 'user_input',
            'confirmed', [$proof->id], ($prior?->version ?? 0) + 1, $prior?->id]);
        $decision = new Decision('decision:'.hash('sha256', $factId), ...[...$scope, $fact->sourceVersion,
            'fact', $prior?->id ?? $fact->id, $fact->id, 'user', (string) $actor->id, trim($input['reason']), $fact->version, [$proof->id]]);
        $this->models->saveSourceModel([], [$fact], [$proof]);
        $this->models->applyDecision($decision, $fact);

        return ['fact_id' => $fact->id, 'decision_id' => $decision->id, 'entity_id' => $fact->entityId,
            'entity_source_version' => $fact->sourceVersion, 'value' => $number, 'unit' => $unit, 'basis' => $input['basis']];
    }
}
