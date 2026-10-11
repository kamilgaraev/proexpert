<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkItemLocator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveEvaluationWorkQuantity
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private ProjectModelRepository $models, private UserWorkQuantityFactWriter $numbers, private UserEvaluationChoiceWriter $choices) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        $native = isset($input['source_fact_id']);
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['work_item_key'] ?? null) || trim($input['work_item_key']) === '' || strlen($input['work_item_key']) > 160
            || ! is_string($input['reason'] ?? null) || trim($input['reason']) === '' || strlen($input['reason']) > 1000
            || ($native && (! is_string($input['source_fact_id']) || strlen($input['source_fact_id']) > 191
                || ! is_int($input['source_fact_version'] ?? null) || $input['source_fact_version'] < 1
                || ! is_string($input['source_value_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['source_value_hash']) !== 1
                || isset($input['value']) || isset($input['unit'])))
            || (! $native && (! is_string($input['value'] ?? null) || preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,4})?\z/', $input['value']) !== 1
                || ! OrdinaryEstimateDecimal::fitsQuantity($input['value']) || ! BigDecimal::of($input['value'])->isGreaterThan(0)
                || ! is_string($input['unit'] ?? null) || DirectQuantityBinding::canonicalUnit($input['unit']) === null))) {
            throw ValidationException::withMessages(['quantity' => 'Укажите работу, положительный объём до четырёх знаков после запятой с единицей либо текущий проверенный факт и основание выбора.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input, $native): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_quantity_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $existing = $receipts[$input['request_id']];
                if ($existing['actor_id'] !== (int) $actor->id || ! hash_equals($existing['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => 'Этот идентификатор уже использован для другого подтверждения объёма.']);
                }

                return $existing['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents', 'failed'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['quantity' => 'Предел подтверждений в этой сессии превышен.']);
            }
            $item = (new EvaluationWorkItemLocator)->find($session->draft_payload ?? [], $payload, $input['work_item_key']);
            $scope = [(int) $session->organization_id, $projectId, $sessionId];
            $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
            if (count($snapshot->facts) > 10000) {
                throw ValidationException::withMessages(['quantity' => 'Разделите проект: предел фактов превышен.']);
            }
            $source = null;
            if ($native) {
                $proofs = array_column($snapshot->evidence, null, 'id');
                foreach ($snapshot->facts as $fact) {
                    if ($fact->id === $input['source_fact_id'] && $fact->version === $input['source_fact_version']
                        && hash_equals($input['source_value_hash'], DirectQuantityBinding::valueHash($fact->value))
                        && $fact->origin === 'document' && $fact->status === 'confirmed'
                        && (is_string($fact->value) || is_int($fact->value)) && OrdinaryEstimateDecimal::fitsQuantity($fact->value)
                        && BigDecimal::of((string) $fact->value)->isGreaterThan(0) && $fact->evidenceIds !== []) {
                        foreach ($fact->evidenceIds as $id) {
                            $proof = $proofs[$id] ?? null;
                            if ($proof !== null && (new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\NativeNumericFactProof)->matches($fact, $proof)) {
                                $source = $fact;
                            }
                        }
                    }
                }
                if (! $source instanceof Fact) {
                    throw ValidationException::withMessages(['source_fact_id' => 'Числовой факт изменился, не проверен парсером или больше не относится к текущему проекту.']);
                }
            } else {
                $source = $this->numbers->write($actor, $session, $item['key'], $input['value'], $input['unit'], $input['reason'], $input['request_id']);
            }
            $canonicalUnit = DirectQuantityBinding::canonicalUnit($source->unit);
            if ($canonicalUnit === null || NormativeUnitNormalizer::safeQuantityFactorDecimal($canonicalUnit, (string) ($item['unit'] ?? '')) === null) {
                throw ValidationException::withMessages(['unit' => 'Единица факта не совместима с единицей выбранной работы.']);
            }
            $selection = ['answer_kind' => 'direct_quantity', 'work_item_key' => $item['key'], 'quantity_key' => DirectQuantityBinding::key($item['key']),
                'source_fact_id' => $source->id, 'source_fact_version' => $source->version, 'source_version' => $source->sourceVersion,
                'source_entity_id' => $source->entityId, 'source_unit' => $source->unit, 'source_value_hash' => DirectQuantityBinding::valueHash($source->value)];
            $proof = $this->choices->write($actor, $session, 'work:'.$item['key'], 'evaluation_answer', $selection, $input['reason'], $input['request_id']);
            $payload['quantity_selections'][$item['key']] = [...$selection, ...$proof];
            $payload['quantity_selection_revision'] = ($payload['quantity_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'quantity_key' => $selection['quantity_key'], 'source_fact_id' => $source->id,
                'request_id' => $input['request_id'], 'state_version' => $session->state_version + 1, 'cost_units' => '0', 'recalculation_required' => true];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['evaluation_quantity_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
