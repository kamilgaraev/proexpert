<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use DateTimeImmutable;

final readonly class AiAttemptBudgetAuthorizer implements AiAttemptAuthorizer
{
    public function __construct(
        private EffectiveSettingsResolver $settings,
        private AiPricingCatalog $pricing,
        private AiBudgetGuard $budgets,
    ) {}

    public function authorize(
        AiOperationContext $context,
        string $provider,
        string $model,
        int $maxInputTokens,
        int $maxOutputTokens,
        int $imageCount = 0,
        int $pageCount = 0,
    ): AiPriceSnapshot {
        $effective = $this->settings->forOperation($context->correlationId, $context->organizationId, $context->sessionId);
        $global = $this->settings->globalForOperation($context->correlationId, $context->organizationId, $context->sessionId);
        $price = $this->pricing->resolve($context->operation, $provider, $model, new DateTimeImmutable);
        $this->budgets->reserve(
            $context,
            $global,
            $effective,
            $price,
            $maxInputTokens,
            $maxOutputTokens,
            $imageCount,
            $pageCount,
        );

        return $price;
    }

    public function markSent(string $attemptId): void
    {
        $this->budgets->markSent($attemptId);
    }

    public function releaseBeforeWire(string $attemptId): void
    {
        $this->budgets->releaseBeforeWire($attemptId);
    }
}
