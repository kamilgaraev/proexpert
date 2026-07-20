<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final readonly class ResidentialResourceConversionEligibility
{
    public function __construct(
        private ResidentialMaterialScenarioCatalog $scenarioCatalog = new ResidentialMaterialScenarioCatalog,
    ) {}

    /** @param list<array<string, mixed>> $intents */
    public function allows(array $intents, string $normCode): bool
    {
        if ($normCode === '') {
            return false;
        }

        foreach ($intents as $intent) {
            if (! is_array($intent)
                || ObjectTypeSignalClassifier::canonical((string) ($intent['object_type'] ?? '')) !== 'residential') {
                continue;
            }
            $scenario = $intent['specialization_scenario'] ?? null;
            $workItemKey = is_array($scenario) ? trim((string) ($scenario['work_item_key'] ?? '')) : '';
            $resolved = $workItemKey === ''
                ? null
                : $this->scenarioCatalog->resolve($scenario, $workItemKey, 'residential');
            if (is_array($resolved) && trim((string) ($resolved['normative_rate_code'] ?? '')) === $normCode) {
                return true;
            }
        }

        return false;
    }
}
