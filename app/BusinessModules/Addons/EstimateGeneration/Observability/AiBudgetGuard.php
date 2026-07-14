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
        if (! $price->available || $global->scope !== 'global' || $organization->scope !== 'organization'
            || $organization->organizationId !== $context->organizationId
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

        $result = $this->database->selectOne(
            'SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?::jsonb) AS reservation_id',
            [$context->attemptId, $context->organizationId, $context->sessionId, $global->snapshotId,
                $organization->snapshotId, $cost->amount, $cost->currency, json_encode($price->toArray(), JSON_THROW_ON_ERROR)],
        );
        if (! is_object($result) || ! is_string($result->reservation_id ?? null)) {
            throw new DomainException('estimate_generation_ai_budget_reservation_failed');
        }
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
