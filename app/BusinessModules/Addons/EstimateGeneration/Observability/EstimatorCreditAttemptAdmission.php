<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditQuote;
use App\Models\Credits\AICreditReservation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/** Runs inside the same transaction that marks a physical attempt wire_started. */
final readonly class EstimatorCreditAttemptAdmission
{
    public function __construct(private Connection $database) {}

    public function admit(string $operationId, string $attemptId, int $organizationId, int $projectId, int $sessionId,
        string $role, AiCost $maximumCost): int
    {
        if ($this->database->transactionLevel() < 1 || ! Str::isUuid($operationId) || ! Str::isUuid($attemptId)
            || $maximumCost->pricingStatus !== 'available' || $maximumCost->currency !== 'RUB' || $maximumCost->amount === null) {
            throw new UsageInvariantViolation('estimator_credit_admission_invalid');
        }
        if (! \App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorCreditPolicy::enabledForOrganization($organizationId)) {
            throw new SessionAiCostLimitReached('estimator_credit_operations_disabled');
        }
        $maximum = BigDecimal::of($maximumCost->amount)->multipliedBy('1000000')->toScale(0, RoundingMode::Ceiling)->toInt();
        $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->lockForUpdate()->first();
        if ($operation === null || ! in_array($operation->status, ['reserved', 'running'], true) || $maximum < 1) {
            throw new SessionAiCostLimitReached('estimator_credit_operation_not_reserved');
        }
        $session = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession::query()
            ->whereKey($sessionId)->where('organization_id', $organizationId)->where('project_id', $projectId)->firstOrFail();
        if ($session->status->isTerminal() || in_array($session->status->value, ['applying', 'failed'], true)
            || ! hash_equals((string) $operation->input_hash,
                (new \App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint)->fromSession($session))) {
            throw new SessionAiCostLimitReached('estimator_credit_approved_inputs_changed');
        }
        $physical = $this->database->table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attemptId)
            ->where('organization_id', $organizationId)->where('project_id', $projectId)->where('session_id', $sessionId)->first(['unit_id']);
        if ($physical?->unit_id !== null) {
            $unit = \App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit::query()
                ->whereKey($physical->unit_id)->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('session_id', $sessionId)->lockForUpdate()->firstOrFail();
            if (($unit->metadata['credit_operation_id'] ?? null) !== $operationId
                || ! app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCreditBinding::class)->canDispatch($unit)) {
                throw new SessionAiCostLimitReached('estimator_credit_document_not_approved');
            }
        }
        $reservation = AICreditReservation::query()->whereKey($operation->reservation_id)->where('organization_id', $organizationId)
            ->where('user_id', $operation->actor_user_id)->where('ai_credit_quote_id', $operation->quote_id)->lockForUpdate()->first();
        if ($reservation === null || $reservation->status !== 'reserved' || $reservation->reserved_minor < 1) {
            throw new SessionAiCostLimitReached('estimator_credit_reservation_not_active');
        }
        $plan = json_decode($operation->processing_plan, true, 64, JSON_THROW_ON_ERROR);
        app(\App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorDocumentCostAllocation::class)->validate($session, $plan);
        $identity = json_decode($operation->credit_request, true, 64, JSON_THROW_ON_ERROR);
        if ($operation->kind === 'rebuild'
            && ($plan['operation_parameters']['local_estimate_key'] ?? null) !== ($session->input_payload['rebuild_section_key'] ?? null)) {
            throw new SessionAiCostLimitReached('estimator_credit_rebuild_target_changed');
        }
        $quote = AICreditQuote::query()->whereKey($operation->quote_id)->where('organization_id', $organizationId)
            ->where('user_id', $operation->actor_user_id)->first();
        if ($quote === null || $quote->profile !== \App\Services\Credits\EstimatorCreditRequest::PROFILE
            || ! hash_equals((string) $operation->plan_hash, hash('sha256', CanonicalPipelineJson::encode($plan)))
            || CanonicalPipelineJson::encode($identity) !== CanonicalPipelineJson::encode($quote->pricing['estimator_identity'] ?? [])
            || ($identity['operation_id'] ?? null) !== $operationId || ($identity['processing_plan_hash'] ?? null) !== $operation->plan_hash
            || ($identity['maximum_calls'] ?? null) !== (int) $operation->maximum_calls
            || ($identity['maximum_provider_cost_micro_rub'] ?? null) !== (int) $operation->maximum_provider_cost_micro_rub) {
            throw new UsageInvariantViolation('estimator_credit_approved_identity_mismatch');
        }
        $rolePlan = $plan['roles'][$role] ?? null;
        if (! is_array($rolePlan) || ! is_int($rolePlan['max_calls'] ?? null) || $rolePlan['max_calls'] < 1
            || ! is_int($rolePlan['maximum_cost_micro_rub'] ?? null) || $maximum > $rolePlan['maximum_cost_micro_rub']) {
            throw new SessionAiCostLimitReached('estimator_credit_call_not_planned');
        }
        $currentTariff = app(AiPricingCatalog::class)->resolve($role, 'timeweb', 'openai/gpt-6-luna', new \DateTimeImmutable);
        $approvedTariff = AiPriceSnapshot::fromArray($identity['supplier_tariff'] ?? []);
        if ($currentTariff->version !== $approvedTariff->version || $currentTariff->effectiveAt !== $approvedTariff->effectiveAt
            || $currentTariff->currency !== $approvedTariff->currency || $currentTariff->reasoningMode !== $approvedTariff->reasoningMode) {
            throw new SessionAiCostLimitReached('estimator_credit_supplier_tariff_changed');
        }
        foreach ($currentTariff->rates as $key => $rate) {
            if (! isset($approvedTariff->rates[$key]) || ! BigDecimal::of($rate)->isEqualTo($approvedTariff->rates[$key])) {
                throw new SessionAiCostLimitReached('estimator_credit_supplier_tariff_changed');
            }
        }
        $attempts = $this->database->table('estimate_generation_credit_attempts')->where('operation_id', $operationId)->lockForUpdate()->get();
        if ($attempts->contains(fn ($attempt): bool => $attempt->attempt_id === $attemptId)) {
            throw new UsageInvariantViolation('estimator_credit_attempt_already_admitted');
        }
        $roleCalls = $attempts->where('role', $role)->count();
        if ($attempts->count() >= (int) $operation->maximum_calls || $roleCalls >= $rolePlan['max_calls']) {
            throw new SessionAiCostLimitReached('estimator_credit_call_limit_reached');
        }
        // Keep unresolved upper exposure. Completed calls contribute their measured supplier cost, including failures.
        $exposure = BigDecimal::of((string) ($plan['document_costs']['cost_micro_rub'] ?? 0));
        $usages = $this->database->table('ai_credit_provider_usages')->where('ai_credit_reservation_id', $reservation->id)
            ->whereIn('usage_key', $attempts->pluck('attempt_id')->all())->get(['usage_key', 'cost_micro_rub'])->keyBy('usage_key');
        foreach ($attempts as $attempt) {
            $usage = $usages->get($attempt->attempt_id);
            if ($usage === null || $attempt->status !== 'recorded') {
                $exposure = $exposure->plus((string) $attempt->maximum_cost_micro_rub);
            } else {
                $exposure = $exposure->plus((string) $usage->cost_micro_rub);
            }
        }
        if ($exposure->plus($maximum)->isGreaterThan((string) $operation->maximum_provider_cost_micro_rub)) {
            throw new SessionAiCostLimitReached('estimator_credit_supplier_limit_reached');
        }
        app(EstimatorServiceExpenseGuard::class)->admit($organizationId, $maximum);
        $this->database->table('estimate_generation_credit_attempts')->insert(['attempt_id' => $attemptId, 'operation_id' => $operationId,
            'organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId, 'reservation_id' => $reservation->id,
            'role' => $role, 'maximum_cost_micro_rub' => $maximum, 'status' => 'admitted', 'created_at' => now(), 'updated_at' => now()]);
        $this->database->table('estimate_generation_credit_operations')->where('operation_id', $operationId)->update(['status' => 'running', 'updated_at' => now()]);

        return (int) $reservation->id;
    }
}
