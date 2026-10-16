<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/** A global live QA run counts supplier expenses, including paid successes, failures and unknown outcomes. */
final readonly class EstimatorQaSupplierExpenseGuard
{
    public const MAXIMUM_UNITS_MINOR = 50000;

    public function __construct(private Connection $database) {}

    public function lockForReservation(): void
    {
        if (config('estimate-generation.live_qa.enabled') !== true) {
            return;
        }
        $runId = config('estimate-generation.live_qa.run_id');
        if ($this->database->transactionLevel() < 1 || ! is_string($runId) || ! Str::isUuid($runId)) {
            throw new SessionAiCostLimitReached('estimator_live_qa_configuration_invalid');
        }
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate_generation_live_qa:'.strtolower($runId)]);
    }

    public function assertReservationBudget(array $plan, int $maximumUnitsMinor): void
    {
        $budget = $this->quoteBudget();
        if ($budget === null) {
            return;
        }
        $approved = $plan['qa_expense_budget'] ?? [];
        foreach (['contract_version', 'run_id', 'unit_cost_micro_rub', 'unit_minor', 'price_version'] as $key) {
            if (($approved[$key] ?? null) !== $budget[$key]) {
                throw new SessionAiCostLimitReached('estimator_live_qa_configuration_changed');
            }
        }
        if ($maximumUnitsMinor < 1 || $maximumUnitsMinor > $budget['remaining_units_minor']) {
            throw new SessionAiCostLimitReached('estimator_live_qa_expense_limit_reached');
        }
    }

    public function quoteBudget(): ?array
    {
        if (config('estimate-generation.live_qa.enabled') !== true) {
            return null;
        }
        $runId = config('estimate-generation.live_qa.run_id');
        $unitCost = BigDecimal::of((string) config('ai-assistant-credits.rub_per_unit', '0.18'))
            ->multipliedBy('1000000')->toScale(0, RoundingMode::Floor)->toInt();
        $unitMinor = (int) config('ai-assistant-credits.unit_minor', 100);
        $priceVersion = (int) config('ai-assistant-credits.price_version', 2);
        if ($this->database->transactionLevel() < 1 || ! is_string($runId) || ! Str::isUuid($runId)
            || $unitCost < 1 || $unitMinor !== 100 || $priceVersion < 1
            || (int) config('ai-assistant-credits.minimum_units_minor', 50) !== 50
            || (int) config('ai-assistant-credits.charge_step_minor', 50) !== 50) {
            throw new SessionAiCostLimitReached('estimator_live_qa_configuration_invalid');
        }
        $runId = strtolower($runId);
        $cap = BigDecimal::of(self::MAXIMUM_UNITS_MINOR)->multipliedBy($unitCost)
            ->dividedBy($unitMinor, 0, RoundingMode::Floor)->toInt();
        $run = $this->database->table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->first();
        if ($run !== null && ($run->status !== 'active' || (int) $run->maximum_units_minor !== self::MAXIMUM_UNITS_MINOR
            || (int) $run->unit_cost_micro_rub !== $unitCost || (int) $run->unit_minor !== $unitMinor
            || (int) $run->price_version !== $priceVersion || (int) $run->maximum_cost_micro_rub !== $cap)) {
            throw new SessionAiCostLimitReached('estimator_live_qa_configuration_changed');
        }
        $costs = [];
        $charged = [];
        foreach ($this->exposureGroups($runId) as $group) {
            $costs[$group->operation_key] = (string) $group->supplier_exposure;
            $charged[$group->operation_key] = (int) $group->charged_units_minor;
        }
        foreach ($this->database->select(<<<'SQL'
SELECT operations.operation_id::text AS operation_key, reservations.reserved_minor
FROM estimate_generation_credit_operations operations
JOIN ai_credit_reservations reservations ON reservations.id=operations.reservation_id
WHERE reservations.status='reserved' AND (
  operations.processing_plan->'qa_expense_budget'->>'run_id'=?
  OR EXISTS (SELECT 1 FROM estimate_generation_qa_expense_attempts attempts
    JOIN estimate_generation_vision_physical_attempts physical ON physical.attempt_id=attempts.attempt_id
    WHERE attempts.run_id=? AND physical.credit_operation_id=operations.operation_id))
SQL, [$runId, $runId]) as $hold) {
            $costs[$hold->operation_key] ??= '0';
            $charged[$hold->operation_key] = max($charged[$hold->operation_key] ?? 0, (int) $hold->reserved_minor);
        }
        $remaining = intdiv(max(0, self::MAXIMUM_UNITS_MINOR - self::exposureUnitsMinor($costs, $unitCost, $charged)), 50) * 50;
        if ($remaining < 50) {
            throw new SessionAiCostLimitReached('estimator_live_qa_expense_limit_reached');
        }

        return ['contract_version' => 'estimator-live-qa-quote-budget:v1', 'run_id' => $runId,
            'remaining_units_minor' => $remaining, 'unit_cost_micro_rub' => $unitCost, 'unit_minor' => $unitMinor,
            'price_version' => $priceVersion, 'maximum_provider_cost_micro_rub' => BigDecimal::of($remaining)
                ->multipliedBy($unitCost)->dividedBy($unitMinor, 0, RoundingMode::Floor)->toInt()];
    }

    public function admit(string $attemptId, AiCost $maximum): void
    {
        if (config('estimate-generation.live_qa.enabled') !== true) {
            return;
        }
        $runId = config('estimate-generation.live_qa.run_id');
        if ($this->database->transactionLevel() < 1 || ! is_string($runId) || ! Str::isUuid($runId)
            || ! Str::isUuid($attemptId) || $maximum->currency !== 'RUB' || $maximum->amount === null) {
            throw new SessionAiCostLimitReached('estimator_live_qa_configuration_invalid');
        }
        $next = BigDecimal::of($maximum->amount)->multipliedBy('1000000')->toScale(0, RoundingMode::Ceiling)->toInt();
        $unitCost = BigDecimal::of((string) config('ai-assistant-credits.rub_per_unit', '0.18'))
            ->multipliedBy('1000000')->toScale(0, RoundingMode::Floor)->toInt();
        $unitMinor = (int) config('ai-assistant-credits.unit_minor', 100);
        $priceVersion = (int) config('ai-assistant-credits.price_version', 2);
        if ($next < 1 || $unitCost < 1 || $unitMinor !== 100 || $priceVersion < 1) {
            throw new SessionAiCostLimitReached('estimator_live_qa_configuration_invalid');
        }
        $cap = BigDecimal::of(self::MAXIMUM_UNITS_MINOR)->multipliedBy($unitCost)
            ->dividedBy($unitMinor, 0, RoundingMode::Floor)->toInt();
        $runId = strtolower($runId);
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate_generation_live_qa:'.$runId]);
        $this->database->table('estimate_generation_qa_expense_runs')->insertOrIgnore([
            'run_id' => $runId, 'maximum_units_minor' => self::MAXIMUM_UNITS_MINOR, 'unit_cost_micro_rub' => $unitCost,
            'unit_minor' => $unitMinor, 'maximum_cost_micro_rub' => $cap, 'price_version' => $priceVersion, 'status' => 'active', 'created_at' => now(),
        ]);
        $run = $this->database->table('estimate_generation_qa_expense_runs')->where('run_id', $runId)->lockForUpdate()->firstOrFail();
        if ($run->status !== 'active' || (int) $run->unit_cost_micro_rub !== $unitCost
            || (int) $run->unit_minor !== $unitMinor || (int) $run->price_version !== $priceVersion
            || (int) $run->maximum_cost_micro_rub !== $cap) {
            throw new SessionAiCostLimitReached('estimator_live_qa_configuration_changed');
        }
        $physical = $this->database->table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attemptId)->lockForUpdate()->first();
        if ($physical === null || $physical->state !== 'pre_wire') {
            throw new UsageInvariantViolation('estimator_live_qa_physical_attempt_not_prepared');
        }
        $existing = $this->database->table('estimate_generation_qa_expense_attempts')->where('attempt_id', $attemptId)->first();
        if ($existing !== null) {
            throw new UsageInvariantViolation('estimator_live_qa_physical_attempt_already_admitted');
        }
        $nextKey = $physical->credit_operation_id ?? $physical->organization_id.':'.($physical->logical_request_fingerprint ?? $attemptId);
        if ($physical->credit_operation_id !== null) {
            $quote = $this->database->table('estimate_generation_credit_operations as operations')
                ->join('ai_credit_quotes as quotes', 'quotes.id', '=', 'operations.quote_id')
                ->where('operations.operation_id', $physical->credit_operation_id)->first(['quotes.pricing']);
            $pricing = $quote === null ? [] : json_decode($quote->pricing, true, 64, JSON_THROW_ON_ERROR);
            if (($pricing['unit_cost_micro_rub'] ?? null) !== $unitCost || ($pricing['unit_minor'] ?? null) !== $unitMinor) {
                throw new SessionAiCostLimitReached('estimator_live_qa_configuration_changed');
            }
        }
        $groups = $this->exposureGroups($runId);
        $supplierExposure = BigDecimal::of($next);
        $costs = [$nextKey => (string) $next];
        $charged = [];
        foreach ($groups as $group) {
            $supplierExposure = $supplierExposure->plus((string) $group->supplier_exposure);
            $costs[$group->operation_key] = (string) BigDecimal::of($costs[$group->operation_key] ?? '0')->plus((string) $group->supplier_exposure);
            $charged[$group->operation_key] = (int) $group->charged_units_minor;
        }
        if ($supplierExposure->isGreaterThan($cap) || self::exposureUnitsMinor($costs, $unitCost, $charged) > self::MAXIMUM_UNITS_MINOR) {
            throw new SessionAiCostLimitReached('estimator_live_qa_expense_limit_reached');
        }
        $this->database->table('estimate_generation_qa_expense_attempts')->insert([
            'attempt_id' => $attemptId, 'run_id' => $runId, 'maximum_cost_micro_rub' => $next, 'created_at' => now(),
        ]);
    }

    private function exposureGroups(string $runId): array
    {
        return $this->database->select(<<<'SQL'
SELECT COALESCE(allocated_operation.operation_id::text, physical.credit_operation_id::text,
    physical.organization_id::text || ':' || COALESCE(physical.logical_request_fingerprint, physical.attempt_id::text)) AS operation_key,
  SUM(CASE
    WHEN usages.usage_status='measured' AND usages.pricing_status='available' AND usages.currency='RUB'
      AND usages.cost_amount IS NOT NULL THEN CEIL(usages.cost_amount*1000000)
    ELSE attempts.maximum_cost_micro_rub END) AS supplier_exposure,
  COALESCE(MAX(operations.consumed_units_minor),0) AS charged_units_minor
FROM estimate_generation_qa_expense_attempts attempts
JOIN estimate_generation_vision_physical_attempts physical ON physical.attempt_id=attempts.attempt_id
LEFT JOIN estimate_generation_ai_usage usages ON usages.attempt_id=attempts.attempt_id
  AND usages.organization_id=physical.organization_id AND usages.project_id=physical.project_id AND usages.session_id=physical.session_id
LEFT JOIN ai_credit_provider_cost_allocations allocation ON allocation.source_attempt_id=physical.attempt_id
  AND allocation.organization_id=physical.organization_id AND allocation.project_id=physical.project_id AND allocation.session_id=physical.session_id
LEFT JOIN estimate_generation_credit_operations allocated_operation ON allocated_operation.operation_id=allocation.destination_operation_id
  AND allocated_operation.processing_plan->'qa_expense_budget'->>'run_id'=attempts.run_id::text
LEFT JOIN estimate_generation_credit_operations operations
  ON operations.operation_id=COALESCE(allocated_operation.operation_id,physical.credit_operation_id)
WHERE attempts.run_id=?
GROUP BY operation_key
SQL, [$runId]);
    }

    /** Includes the estimator's 0.5-unit minimum and rounding once per operation, also for service expenses. */
    public static function exposureUnitsMinor(array $costsMicroRub, int $unitCostMicroRub, array $chargedUnitsMinor = []): int
    {
        if ($unitCostMicroRub < 1) {
            throw new UsageInvariantViolation('estimator_live_qa_unit_price_invalid');
        }
        $total = BigDecimal::zero();
        $denominator = BigDecimal::of($unitCostMicroRub)->multipliedBy(50);
        foreach ($costsMicroRub as $key => $cost) {
            $equivalent = BigDecimal::of((string) $cost)->multipliedBy(100)->dividedBy($denominator, 0, RoundingMode::Ceiling)->multipliedBy(50);
            $total = $total->plus(BigDecimal::max($equivalent, BigDecimal::of($chargedUnitsMinor[$key] ?? 0)));
        }

        return $total->toInt();
    }
}
