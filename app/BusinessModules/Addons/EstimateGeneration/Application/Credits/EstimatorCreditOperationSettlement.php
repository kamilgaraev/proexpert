<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationResultValidator;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditReservation;
use App\Services\Credits\AICreditService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;

/** Invoked in the same aggregate transaction that publishes the validated business result. */
final readonly class EstimatorCreditOperationSettlement
{
    public function __construct(private Connection $database, private AICreditService $credits) {}

    public function publish(EstimateGenerationSession $session, string $operationId, array $draft, ?string $revisionId = null): array
    {
        $contentHash = hash('sha256', CanonicalPipelineJson::encode(array_intersect_key($draft,
            array_flip(['generation_contract', 'source_input_version', 'evaluation_result', 'local_estimates']))));
        $result = $draft['evaluation_result'] ?? null;
        $useful = false;
        if (is_array($result)) {
            (new EvaluationResultValidator)->validate($result);
            foreach ($result['scenarios'] as $scenario) {
                foreach ($scenario['positions'] as $position) {
                    if (($position['quantity'] ?? null) !== null || ($position['unit_price'] ?? null) !== null) {
                        $useful = true;
                    }
                }
            }
        } else {
            foreach ($draft['local_estimates'] ?? [] as $local) {
                foreach ($local['sections'] ?? [] as $section) {
                    foreach ($section['work_items'] ?? [] as $item) {
                        if (($item['quantity'] ?? null) !== null && is_array($item['quantity_evidence'] ?? null)) {
                            $useful = true;
                        }
                    }
                }
            }
        }

        return $this->settle($session, $operationId, $contentHash, $useful, $revisionId);
    }

    /** Dialogue explanations and unapplied changes are not a published estimate revision. */
    public function publishDialogue(EstimateGenerationSession $session, string $operationId, array $result): array
    {
        if (! in_array($result['kind'] ?? null, ['explanation', 'proposal'], true)) {
            throw new UsageInvariantViolation('estimator_credit_dialogue_result_invalid');
        }
        $receipt = $this->settle($session, $operationId, hash('sha256', CanonicalPipelineJson::encode($result)), false, null);

        return $receipt;
    }

    public function publishDocuments(EstimateGenerationSession $session, string $operationId, array $outcome): array
    {
        return $this->settle($session, $operationId, hash('sha256', CanonicalPipelineJson::encode(['prepared_documents' => $outcome])), false, null);
    }

    private function settle(EstimateGenerationSession $session, string $operationId, string $contentHash, bool $useful, ?string $revisionId): array
    {
        $operation = $this->operation($session, $operationId);
        if ($operation->status === 'settled') {
            $receipt = json_decode($operation->receipt, true, 64, JSON_THROW_ON_ERROR);
            if ($operation->result_revision_id !== $revisionId || ($receipt['result_content_hash'] ?? null) !== $contentHash) {
                throw new UsageInvariantViolation('estimator_credit_publication_identity_mismatch');
            }

            return $receipt;
        }
        if (! in_array($operation->status, ['reserved', 'running'], true)) {
            throw new UsageInvariantViolation('estimator_credit_publication_not_active');
        }
        $attempts = $this->database->table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->lockForUpdate()->get();
        if ($attempts->contains(static fn ($attempt): bool => $attempt->status === 'admitted' || $attempt->usage_transferred_at === null)) {
            throw new UsageInvariantViolation('estimator_credit_publication_usage_pending');
        }
        $reservation = AICreditReservation::query()->whereKey($operation->reservation_id)->where('organization_id', $session->organization_id)
            ->where('user_id', $operation->actor_user_id)->where('ai_credit_quote_id', $operation->quote_id)->firstOrFail();
        $usages = $this->database->table('ai_credit_provider_usages')->where('ai_credit_reservation_id', $reservation->id)->get();
        if ($usages->count() !== $attempts->count()) {
            throw new UsageInvariantViolation('estimator_credit_publication_usage_not_reconciled');
        }
        $cost = BigDecimal::zero();
        foreach ($usages as $usage) {
            $cost = $cost->plus((string) $usage->cost_micro_rub);
        }
        if ($cost->isGreaterThan((string) $operation->maximum_provider_cost_micro_rub)) {
            throw new \App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached('estimator_credit_supplier_limit_reached');
        }
        $consumed = $this->credits->finalize($reservation, useful: $useful && $usages->where('is_successful', true)->isNotEmpty());
        $unknown = $attempts->where('status', 'unavailable')->count();
        $receipt = ['operation_id' => $operationId, 'status' => 'settled', 'revision_id' => $revisionId, 'useful_result' => $useful,
            'result_content_hash' => $contentHash,
            'consumed_units_minor' => $consumed, 'charged_units' => (string) BigDecimal::of((string) $consumed)->withPointMovedLeft(2),
            'measured_supplier_cost_micro_rub' => $cost->toInt(), 'supplier_cost_micro_rub' => $unknown === 0 ? $cost->toInt() : null,
            'unavailable_physical_costs' => $unknown, 'provider' => 'timeweb', 'model' => 'openai/gpt-6-luna'];
        $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)->update([
            'status' => 'settled', 'consumed_units_minor' => $consumed, 'result_revision_id' => $revisionId,
            'receipt' => CanonicalPipelineJson::encode($receipt), 'updated_at' => now()]);

        return $receipt;
    }

    public function abort(EstimateGenerationSession $session, string $operationId, string $reason = 'cancelled'): void
    {
        $operation = $this->operation($session, $operationId);
        if (! in_array($operation->status, ['reserved', 'running'], true)) {
            return;
        }
        $reservation = AICreditReservation::query()->whereKey($operation->reservation_id)->where('organization_id', $session->organization_id)
            ->where('user_id', $operation->actor_user_id)->where('ai_credit_quote_id', $operation->quote_id)->firstOrFail();
        $this->credits->cancel($reservation);
        $receipt = ['operation_id' => $operationId, 'status' => $reason === 'cancelled' ? 'cancelled' : 'failed',
            'consumed_units_minor' => 0, 'charged_units' => '0', 'reason' => $reason];
        $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)->update([
            'status' => $receipt['status'], 'consumed_units_minor' => 0, 'receipt' => CanonicalPipelineJson::encode($receipt), 'updated_at' => now()]);
    }

    public function abortForSession(EstimateGenerationSession $session, string $reason = 'cancelled'): void
    {
        $ids = $this->database->table('estimate_generation_credit_operations')->where('organization_id', $session->organization_id)
            ->where('project_id', $session->project_id)->where('session_id', $session->id)->whereIn('status', ['reserved', 'running'])
            ->orderBy('operation_id')->pluck('operation_id');
        foreach ($ids as $id) {
            $this->abort($session, $id, $reason);
        }
    }

    private function operation(EstimateGenerationSession $session, string $operationId): object
    {
        if ($this->database->transactionLevel() < 1) {
            throw new UsageInvariantViolation('estimator_credit_settlement_requires_aggregate_transaction');
        }
        $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->where('session_id', $session->id)->lockForUpdate()->first();
        if ($operation === null) {
            throw new UsageInvariantViolation('estimator_credit_settlement_scope_mismatch');
        }

        return $operation;
    }
}
