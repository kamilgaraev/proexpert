<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/** Service losses have a separate limit from the customer's reserved balance. */
final readonly class EstimatorServiceExpenseGuard
{
    public function __construct(private Connection $database) {}

    public function admit(int $organizationId, int $maximumNextMicroRub): void
    {
        if ($this->database->transactionLevel() < 1 || $organizationId < 1 || $maximumNextMicroRub < 1) {
            throw new UsageInvariantViolation('estimator_service_expense_scope_invalid');
        }
        // Serialize admission across this organization's sessions without creating another wallet.
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate_generation_service_expense:'.$organizationId]);
        foreach (['daily' => now()->startOfDay(), 'monthly' => now()->startOfMonth()] as $period => $start) {
            $limit = BigDecimal::of((string) config('estimate-generation.credits.service_'.$period.'_expense_limit_rub',
                $period === 'daily' ? '250.00' : '4000.00'))->multipliedBy('1000000')->toScale(0, RoundingMode::Floor);
            if (! $limit->isGreaterThan(0)) {
                throw new SessionAiCostLimitReached('estimator_credit_service_expense_limit_invalid');
            }
            $risk = $this->database->selectOne(<<<'SQL'
SELECT COALESCE(SUM(CASE
    WHEN allocation.source_provider_usage_id IS NOT NULL THEN 0
    WHEN attempts.status <> 'recorded' OR usages.id IS NULL THEN attempts.maximum_cost_micro_rub
    WHEN NOT usages.is_successful OR operations.status IN ('cancelled','failed')
       OR (operations.status='settled' AND operations.consumed_units_minor=0)
       OR operations.status IN ('reserved','running') THEN usages.cost_micro_rub
    ELSE 0 END), 0) AS expense_exposure
FROM estimate_generation_credit_attempts attempts
JOIN estimate_generation_credit_operations operations ON operations.operation_id=attempts.operation_id
LEFT JOIN ai_credit_provider_usages usages ON usages.ai_credit_reservation_id=attempts.reservation_id
    AND usages.usage_key=attempts.attempt_id::text
LEFT JOIN ai_credit_provider_cost_allocations allocation ON allocation.source_provider_usage_id=usages.id
WHERE attempts.organization_id=?
  AND (attempts.status <> 'recorded' OR usages.id IS NULL OR usages.occurred_at >= ?)
SQL, [$organizationId, $start]);
            if (BigDecimal::of((string) ($risk?->expense_exposure ?? '0'))->plus($maximumNextMicroRub)->isGreaterThan($limit)) {
                throw new SessionAiCostLimitReached('estimator_credit_service_expense_limit_reached');
            }
        }
    }
}
