<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Closure;
use DateTimeImmutable;

final readonly class CatalogAiPriceSnapshotResolver implements AiPriceSnapshotResolver
{
    /** @param null|Closure(): DateTimeImmutable $clock */
    public function __construct(
        private AiPricingCatalog $pricing,
        private ?Closure $clock = null,
    ) {}

    public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot
    {
        $at = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable;

        return $this->pricing->resolve($context->operation, $provider, $model, $at);
    }
}
