<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;

final readonly class AuthoritativePackagePricingGuard
{
    public function __construct(private AcceptedQuantityEvidenceVerifier $evidence) {}

    /** @param array<string, mixed> $workItem @return list<array{norm_resource_id: int, resource_price_id: int, unit_conversion_id: int|null}>|null */
    public function inputs(int $organizationId, int $projectId, int $sessionId, string $currentVersion, array $workItem): ?array
    {
        if (! $this->evidence->verifyScope($organizationId, $projectId, $sessionId, $currentVersion, $workItem)) {
            return null;
        }
        $inputs = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                $reference = is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : [];
                $normResourceId = $this->positiveInt($reference['norm_resource_id'] ?? null);
                $priceId = $this->positiveInt($reference['price_id'] ?? null);
                if ($normResourceId === null || $priceId === null) {
                    return null;
                }
                $inputs[] = [
                    'norm_resource_id' => $normResourceId,
                    'resource_price_id' => $priceId,
                    'unit_conversion_id' => $this->positiveInt($reference['unit_conversion_id'] ?? null),
                ];
            }
        }

        return $inputs === [] ? null : $inputs;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    }
}
