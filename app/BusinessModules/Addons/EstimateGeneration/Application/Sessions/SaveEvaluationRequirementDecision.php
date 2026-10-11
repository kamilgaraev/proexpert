<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSourceVersion;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveEvaluationRequirementDecision
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private EvidenceRepository $evidence) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null)
            || ! is_string($input['reason'] ?? null) || mb_strlen($input['reason']) < 12 || mb_strlen($input['reason']) > 300 || strlen($input['reason']) > 1000) {
            throw ValidationException::withMessages(['decision' => 'Проверьте запрос и причину подтверждения.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => 'Универсальный процесс не включён для этой сессии.']);
            }
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_decision_receipts'] ?? [];
            $existing = $receipts[$input['request_id']] ?? null;
            if ($existing !== null) {
                if ($existing['actor_id'] !== (int) $actor->id || ! hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'Этот идентификатор уже использован для другого решения.']);
                }

                return $existing['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['request_id' => 'Достигнут предел решений этой сессии.']);
            }
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $snapshot = $this->models->snapshot(...[...$scope, 10001]);
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['evaluation' => 'Разделите состав: предел фактов сессии превышен.']);
            }
            $entities = array_column($snapshot->entities, null, 'id');
            $entity = $entities[$input['entity_id'] ?? ''] ?? null;
            $section = $input['section'] ?? null;
            $requirement = $input['requirement'] ?? null;
            $allowed = ScopeCompletenessEvaluator::requirementsFor($section, $payload['construction_type'] ?? 'new_construction', $payload['profile_id'] ?? 'construction');
            $selected = $session->draft_payload['evaluation_result']['coverage']['selected_scope'][$section] ?? [];
            if ($entity === null || ! in_array($entity->id, $selected, true) || ! in_array($requirement, $allowed, true)) {
                throw ValidationException::withMessages(['requirement' => 'Требование не относится к выбранной сущности и разделу.']);
            }
            $technology = (string) ($payload['technology_selections'][$section][$entity->id] ?? 'unspecified');
            $value = ['disposition' => $input['disposition'] ?? null, 'scope_version' => ScopeCompletenessEvaluator::VERSION,
                'technology_key' => $technology, 'section' => $section, 'reason' => $input['reason']];
            if ($value['disposition'] === 'document_verified') {
                $documentFact = array_column($snapshot->facts, null, 'id')[$input['document_fact_id'] ?? ''] ?? null;
                $proofs = array_column($snapshot->evidence, null, 'id');
                if ($documentFact === null || $documentFact->entityId !== $entity->id || $documentFact->type !== $requirement
                    || $documentFact->origin !== 'document' || $documentFact->status !== 'confirmed' || $documentFact->evidenceIds === []) {
                    throw ValidationException::withMessages(['document_fact_id' => 'Выберите актуальное основание именно этого требования.']);
                }
                $documentRef = null;
                foreach ($documentFact->evidenceIds as $id) {
                    $proof = $proofs[$id] ?? null;
                    $reference = $proof?->sourceReference ?? $proof?->sourceArtifactId;
                    if ($proof === null || $proof->sourceType !== 'document' || $proof->sourceVersion !== $documentFact->sourceVersion
                        || ! is_string($reference) || preg_match('/\Adocument:([1-9][0-9]*)\z/', $reference, $match) !== 1) {
                        throw ValidationException::withMessages(['document_fact_id' => 'У документа отсутствует проверяемый источник.']);
                    }
                    $document = $session->documents()->where('organization_id', $scope[0])->where('project_id', $projectId)->whereKey((int) $match[1])->first();
                    if ($document === null || $document->status === 'ignored' || ! hash_equals(DocumentSourceVersion::fromDocument($document), $documentFact->sourceVersion)
                        || ($documentRef !== null && $documentRef !== $reference)) {
                        throw ValidationException::withMessages(['document_fact_id' => 'Версия документа изменилась или документ исключён.']);
                    }
                    $documentRef = $reference;
                }
                $value += ['document_fact_id' => $documentFact->id, 'document_source_version' => $documentFact->sourceVersion, 'document_source_ref' => $documentRef];
            } elseif ($value['disposition'] !== 'not_applicable' || ! in_array($requirement, ['demolition', 'waste_removal', 'existing_structures_protection'], true)) {
                throw ValidationException::withMessages(['disposition' => 'Для этого требования нельзя подтвердить неприменимость.']);
            }
            $previous = null;
            $type = 'evaluation_requirement.'.$requirement;
            foreach ($snapshot->facts as $fact) {
                if ($fact->entityId === $entity->id && $fact->sourceVersion === $entity->sourceVersion && $fact->type === $type) {
                    $previous = $fact;
                }
            }
            $factId = 'fact:'.hash('sha256', $input['request_id'].'|'.$entity->id.'|'.$type);
            $decisionId = 'decision:'.hash('sha256', $input['request_id'].'|'.$factId);
            $value['decision_id'] = $decisionId;
            $hash = hash('sha256', CanonicalPipelineJson::encode($value));
            $node = $this->evidence->insertOrGet(new EvidenceData(...[...$scope, EvidenceType::SourceFact, EvidenceSourceType::UserInput,
                'input:'.$sessionId, $entity->sourceVersion, ['source_key' => 'source:'.hash('sha256', $factId), 'native_reference' => 'input_payload.evaluation_choices'],
                ['fact_key' => 'evaluation_choice', 'fact_value' => $hash], 0.0, EvidenceProducer::UserInputNormalizer->value, 'sha256:'.hash('sha256', 'evaluation-choices:v1')]));
            $proofId = 'evidence:'.$node->id;
            $proof = new Evidence($proofId, ...[...$scope, $entity->sourceVersion, 'input:'.$sessionId, 'user_input', null, null,
                'input_payload.evaluation_choices', EvidenceProducer::UserInputNormalizer->value, 'input:'.$sessionId, $hash]);
            $fact = new Fact($factId, ...[...$scope, $entity->sourceVersion, $entity->id, $type, $value, null, 0.0, 'user_input', 'confirmed', [$proofId],
                ($previous?->version ?? 0) + 1, $previous?->id]);
            $decision = new Decision($decisionId, ...[...$scope, $entity->sourceVersion, 'fact', $previous?->id ?? $fact->id, $fact->id,
                'user', (string) $actor->id, $input['reason'], $fact->version, [$proofId]]);
            $this->models->saveSourceModel([], [$fact], [$proof]);
            $this->models->applyDecision($decision, $fact);
            $result = ['request_id' => $input['request_id'], 'state_version' => $session->state_version + 1,
                'fact_id' => $factId, 'decision_id' => $decisionId, 'cost_units' => '0', 'recalculation_required' => true];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['evaluation_decision_receipts'] = $receipts;
            $payload['scope_selection_revision'] = ($payload['scope_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
