<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Catalog;

use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateBinding;
use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateManifest;
use App\BusinessModules\Core\Reporting\Domain\Enums\WaveOneCandidateBindingStatus;
use InvalidArgumentException;

final readonly class WaveOneCandidateBindingSet
{
    private array $bindings;

    public function __construct(WaveOneCandidateManifest $manifest, iterable $bindings)
    {
        $resolved = [];
        foreach ($bindings as $binding) {
            if (! $binding instanceof WaveOneCandidateBinding) {
                throw new InvalidArgumentException('wave_one_candidate_binding_set_invalid');
            }

            if (($binding->status === WaveOneCandidateBindingStatus::IMPLEMENTED && $binding->provider === null)
                || ($binding->status === WaveOneCandidateBindingStatus::BLOCKED_BY_SOURCE_CONTRACT && $binding->provider !== null)) {
                throw new InvalidArgumentException('wave_one_candidate_binding_set_invalid');
            }

            $resolved[] = $binding;
        }

        $manifestCodes = array_map(
            static fn ($candidate): string => $candidate->code,
            $manifest->ordered(),
        );
        $bindingCodes = array_map(
            static fn (WaveOneCandidateBinding $binding): string => $binding->code,
            $resolved,
        );
        if ($bindingCodes !== $manifestCodes) {
            throw new InvalidArgumentException('wave_one_candidate_binding_set_invalid');
        }

        $this->bindings = $resolved;
    }

    public function ordered(): array
    {
        return $this->bindings;
    }

    public function implemented(): array
    {
        return array_values(array_filter(
            $this->bindings,
            static fn (WaveOneCandidateBinding $binding): bool => $binding->status === WaveOneCandidateBindingStatus::IMPLEMENTED,
        ));
    }
}
