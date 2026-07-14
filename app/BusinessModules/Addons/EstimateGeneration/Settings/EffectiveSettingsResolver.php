<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

use Closure;
use DomainException;

final class EffectiveSettingsResolver
{
    private const MAX_CACHED_SNAPSHOTS = 512;

    private Closure $loader;

    private ?Closure $globalLoader;

    /** @var array<string, EffectiveEstimateGenerationSettings> */
    private array $operations = [];

    /** @param callable(int): array<string, mixed> $loader */
    public function __construct(callable $loader, ?callable $globalLoader = null)
    {
        $this->loader = Closure::fromCallable($loader);
        $this->globalLoader = $globalLoader !== null ? Closure::fromCallable($globalLoader) : null;
    }

    public function forOperation(string $operationId, int $organizationId): EffectiveEstimateGenerationSettings
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $operationId) !== 1 || $organizationId < 1) {
            throw new DomainException('estimate_generation_effective_settings_context_invalid');
        }
        if (isset($this->operations[$operationId])) {
            $settings = $this->operations[$operationId];
            if ($settings->scope === 'organization' && $settings->organizationId !== $organizationId) {
                throw new DomainException('estimate_generation_effective_settings_tenant_collision');
            }

            return $settings;
        }

        $record = ($this->loader)($organizationId);
        $settings = EffectiveEstimateGenerationSettings::fromRecord($record, $organizationId);
        $this->remember($operationId, $settings);

        return $settings;
    }

    public function globalForOperation(string $operationId, int $organizationId): EffectiveEstimateGenerationSettings
    {
        if ($this->globalLoader === null) {
            throw new DomainException('estimate_generation_global_settings_loader_missing');
        }
        $key = 'global:'.$operationId;
        if (! isset($this->operations[$key])) {
            $this->remember($key, EffectiveEstimateGenerationSettings::fromRecord(
                ($this->globalLoader)(),
                $organizationId,
            ));
        }

        return $this->operations[$key];
    }

    private function remember(string $key, EffectiveEstimateGenerationSettings $settings): void
    {
        $this->operations[$key] = $settings;
        while (count($this->operations) > self::MAX_CACHED_SNAPSHOTS) {
            array_shift($this->operations);
        }
    }
}
