<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

final class EffectiveSettingsResolver
{
    private const MAX_CACHED_OPERATIONS = 256;

    /** @var array<string, EffectiveSettingsPair> */
    private array $operations = [];

    public function __construct(
        private readonly EffectiveSettingsOperationStore $store,
        private readonly ?string $visionModelOverride = null,
        private readonly string $visionModelFallback = VisionModelPolicy::LUNA,
    ) {}

    public function forOperation(string $correlationId, int $organizationId, int $sessionId): EffectiveEstimateGenerationSettings
    {
        return $this->pair($correlationId, $organizationId, $sessionId)->effective;
    }

    public function globalForOperation(string $correlationId, int $organizationId, int $sessionId): EffectiveEstimateGenerationSettings
    {
        return $this->pair($correlationId, $organizationId, $sessionId)->global;
    }

    public function visionModelForOperation(
        string $correlationId,
        int $organizationId,
        int $sessionId,
        ?string $inheritedModel = null,
    ): string {
        $pair = $this->pair($correlationId, $organizationId, $sessionId, $inheritedModel);

        return VisionModelPolicy::assertSupported($pair->visionModel ?? $pair->effective->model('vision'));
    }

    private function pair(
        string $correlationId,
        int $organizationId,
        int $sessionId,
        ?string $inheritedModel = null,
    ): EffectiveSettingsPair {
        $key = $correlationId.':'.$organizationId.':'.$sessionId;
        if (! isset($this->operations[$key])) {
            $override = $inheritedModel ?? (is_string($this->visionModelOverride) && trim($this->visionModelOverride) !== ''
                ? trim($this->visionModelOverride)
                : null);
            if ($override !== null) {
                VisionModelPolicy::assertSupported($override);
            }
            $fallback = VisionModelPolicy::assertSupported(trim($this->visionModelFallback));
            $this->operations[$key] = $this->store instanceof VisionModelPinningStore
                ? $this->store->pinVision(
                    $correlationId,
                    $organizationId,
                    $sessionId,
                    $override,
                    $fallback,
                )
                : $this->store->pin($correlationId, $organizationId, $sessionId);
            while (count($this->operations) > self::MAX_CACHED_OPERATIONS) {
                array_shift($this->operations);
            }
        }

        return $this->operations[$key];
    }
}
