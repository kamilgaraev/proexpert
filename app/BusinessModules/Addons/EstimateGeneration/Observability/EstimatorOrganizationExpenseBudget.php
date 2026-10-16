<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EstimateGenerationSettingsService;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/** Effective global and organization budgets include every supplier expense, independently of customer charges. */
final readonly class EstimatorOrganizationExpenseBudget
{
    public function __construct(private Connection $database, private EstimateGenerationSettingsService $settings) {}

    public function admit(int $organizationId, int $maximumNextMicroRub): void
    {
        if ($this->database->transactionLevel() < 1 || $organizationId < 1 || $maximumNextMicroRub < 1) {
            throw new UsageInvariantViolation('estimator_organization_budget_scope_invalid');
        }
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate_generation_global_supplier_budget']);
        $this->database->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['estimate_generation_organization_supplier_budget:'.$organizationId]);
        foreach (['global' => null, 'organization' => $organizationId] as $scope => $owner) {
            $snapshot = $this->settings->currentSnapshot($scope, $owner);
            if ($snapshot === null) {
                continue;
            }
            $payload = $snapshot['snapshot'];
            if (! hash_equals($snapshot['snapshot_hash'], SettingsSnapshotHash::calculate($payload))
                || ($payload['budgets']['currency'] ?? null) !== 'RUB') {
                throw new SessionAiCostLimitReached('estimator_credit_organization_budget_invalid');
            }
            $limits = self::limitsMicroRub($payload['budgets']);
            foreach (['daily' => now()->startOfDay(), 'monthly' => now()->startOfMonth()] as $period => $start) {
                $query = <<<'SQL'
SELECT COALESCE(SUM(CASE
    WHEN usages.usage_status='measured' AND usages.pricing_status='available' AND usages.currency='RUB'
      AND usages.cost_amount IS NOT NULL THEN CEIL(usages.cost_amount*1000000)
    WHEN attempts.cost_reservation_currency='RUB' AND attempts.cost_reservation_amount IS NOT NULL
      THEN CEIL(attempts.cost_reservation_amount*1000000)
    ELSE ? END), 0) AS supplier_exposure
FROM estimate_generation_vision_physical_attempts attempts
LEFT JOIN estimate_generation_ai_usage usages ON usages.attempt_id=attempts.attempt_id
  AND usages.organization_id=attempts.organization_id AND usages.project_id=attempts.project_id AND usages.session_id=attempts.session_id
WHERE attempts.wire_started_at IS NOT NULL
  AND CASE
    WHEN usages.usage_status='measured' AND usages.pricing_status='available' AND usages.currency='RUB'
      AND usages.cost_amount IS NOT NULL THEN usages.created_at
    ELSE attempts.wire_started_at
  END >= ?
SQL;
                $arguments = [(string) BigDecimal::of($limits[$period])->plus(1), $start];
                if ($owner !== null) {
                    $query .= ' AND attempts.organization_id=?';
                    $arguments[] = $owner;
                }
                $spent = $this->database->selectOne($query, $arguments);
                if (BigDecimal::of((string) ($spent?->supplier_exposure ?? '0'))->plus($maximumNextMicroRub)->isGreaterThan($limits[$period])) {
                    throw new SessionAiCostLimitReached('estimator_credit_organization_budget_reached');
                }
            }
        }
    }

    public static function limitsMicroRub(array $budgets): array
    {
        if (($budgets['currency'] ?? null) !== 'RUB') {
            throw new SessionAiCostLimitReached('estimator_credit_organization_budget_invalid');
        }
        $result = [];
        foreach (['daily', 'monthly'] as $period) {
            $value = $budgets[$period] ?? null;
            if (! is_string($value) || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', $value) !== 1) {
                throw new SessionAiCostLimitReached('estimator_credit_organization_budget_invalid');
            }
            $limit = BigDecimal::of($value)->multipliedBy('1000000')->toScale(0, RoundingMode::Floor);
            if (! $limit->isGreaterThan(0)) {
                throw new SessionAiCostLimitReached('estimator_credit_organization_budget_invalid');
            }
            $result[$period] = (string) $limit;
        }
        if (BigDecimal::of($result['daily'])->isGreaterThan($result['monthly'])) {
            throw new SessionAiCostLimitReached('estimator_credit_organization_budget_invalid');
        }

        return $result;
    }
}
