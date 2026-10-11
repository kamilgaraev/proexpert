<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class CatalogAiPriceSnapshotResolver implements AiPriceSnapshotResolver
{
    /** @param null|Closure(): DateTimeImmutable $clock */
    public function __construct(
        private AiPricingCatalog $pricing,
        private ?Closure $clock = null,
        private ?Connection $database = null,
    ) {}

    public function resolve(AiOperationContext $context, string $provider, string $model): AiPriceSnapshot
    {
        if ($context->creditOperationId !== null) {
            $operation = ($this->database ?? app('db')->connection())->table('estimate_generation_credit_operations')
                ->where('operation_id', $context->creditOperationId)->where('organization_id', $context->organizationId)
                ->where('project_id', $context->projectId)->where('session_id', $context->sessionId)->first(['credit_request']);
            $identity = $operation === null ? [] : json_decode($operation->credit_request, true, 64, JSON_THROW_ON_ERROR);
            if ($provider !== ($identity['provider'] ?? null) || $model !== ($identity['model'] ?? null)) {
                throw new UsageInvariantViolation('estimator_credit_tariff_scope_mismatch');
            }

            return AiPriceSnapshot::fromArray($identity['supplier_tariff'] ?? []);
        }
        $at = $this->clock !== null ? ($this->clock)() : new DateTimeImmutable;

        return $this->pricing->resolve($context->operation, $provider, $model, $at);
    }
}
