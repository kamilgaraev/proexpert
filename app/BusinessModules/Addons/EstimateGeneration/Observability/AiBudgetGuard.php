<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use DomainException;
use Illuminate\Database\Connection;

final readonly class AiBudgetGuard
{
    public function __construct(private Connection $database, private AiCostCalculator $calculator) {}

    public function reserve(
        AiOperationContext $context,
        EffectiveEstimateGenerationSettings $global,
        EffectiveEstimateGenerationSettings $organization,
        AiPriceSnapshot $price,
        int $maxInputTokens,
        int $maxOutputTokens,
        int $imageCount = 0,
        int $pageCount = 0,
    ): void {
        if (! $price->available || $global->scope !== 'global'
            || ($organization->scope === 'organization' && $organization->organizationId !== $context->organizationId)
            || $price->currency !== $global->currency() || $price->currency !== $organization->currency()) {
            throw new DomainException('estimate_generation_ai_budget_context_invalid');
        }
        $cost = $this->calculator->calculate(
            $maxInputTokens,
            0,
            $maxOutputTokens,
            0,
            $imageCount,
            $pageCount,
            $price->toArray(),
        );
        if ($cost->pricingStatus !== 'available' || $cost->amount === null || $cost->currency === null) {
            throw new DomainException('estimate_generation_ai_budget_price_unavailable');
        }

        $priceJson = json_encode($price->toArray(), JSON_THROW_ON_ERROR);
        $fingerprint = 'sha256:'.hash('sha256', json_encode([
            'attempt_id' => $context->attemptId,
            'correlation_id' => $context->correlationId,
            'organization_id' => $context->organizationId,
            'session_id' => $context->sessionId,
            'global_snapshot_id' => $global->snapshotId,
            'effective_snapshot_id' => $organization->snapshotId,
            'reserved_amount' => $cost->amount,
            'currency' => $cost->currency,
            'price_snapshot' => $price->toArray(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $result = $this->database->selectOne(
            'SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) AS reservation_id',
            [$context->attemptId, $context->correlationId, $context->organizationId, $context->sessionId, $global->snapshotId,
                $organization->snapshotId, $cost->amount, $cost->currency, $priceJson, $fingerprint],
        );
        if (! is_object($result) || ! is_string($result->reservation_id ?? null)) {
            throw new DomainException('estimate_generation_ai_budget_reservation_failed');
        }
    }

    public function markSent(string $attemptId): void
    {
        $this->database->selectOne('SELECT eg_mark_ai_budget_sent(?) AS marked', [$attemptId]);
    }

    public function releaseBeforeWire(string $attemptId): void
    {
        $this->database->selectOne('SELECT eg_release_ai_budget(?) AS released', [$attemptId]);
    }

    public function pendingReconciliation(string $attemptId): void
    {
        $this->database->selectOne('SELECT eg_mark_ai_budget_reconciliation(?) AS pending', [$attemptId]);
    }

    public function settle(string $attemptId, AiCost $actual): void
    {
        if ($actual->pricingStatus !== 'available' || $actual->amount === null || $actual->currency === null) {
            throw new DomainException('estimate_generation_ai_budget_settlement_unpriced');
        }
        $this->database->selectOne('SELECT eg_settle_ai_budget(?, ?, ?) AS settled', [
            $attemptId, $actual->amount, $actual->currency,
        ]);
    }
}
