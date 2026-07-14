<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

final class EffectiveSettingsResolver
{
    private const MAX_CACHED_OPERATIONS = 256;

    /** @var array<string, EffectiveSettingsPair> */
    private array $operations = [];

    public function __construct(private readonly EffectiveSettingsOperationStore $store) {}

    public function forOperation(string $correlationId, int $organizationId, int $sessionId): EffectiveEstimateGenerationSettings
    {
        return $this->pair($correlationId, $organizationId, $sessionId)->effective;
    }

    public function globalForOperation(string $correlationId, int $organizationId, int $sessionId): EffectiveEstimateGenerationSettings
    {
        return $this->pair($correlationId, $organizationId, $sessionId)->global;
    }

    private function pair(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
    {
        $key = $correlationId.':'.$organizationId.':'.$sessionId;
        if (! isset($this->operations[$key])) {
            $this->operations[$key] = $this->store->pin($correlationId, $organizationId, $sessionId);
            while (count($this->operations) > self::MAX_CACHED_OPERATIONS) {
                array_shift($this->operations);
            }
        }

        return $this->operations[$key];
    }
}
