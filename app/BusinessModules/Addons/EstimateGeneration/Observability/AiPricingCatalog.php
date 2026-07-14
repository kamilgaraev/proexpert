<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use DateTimeImmutable;
use DomainException;
use Throwable;

final readonly class AiPricingCatalog
{
    /** @param array<string, mixed> $catalog */
    public function __construct(private array $catalog) {}

    public function resolve(string $operation, string $provider, string $model, DateTimeImmutable $at): AiPriceSnapshot
    {
        $versions = $this->catalog[$operation][$provider][$model] ?? null;
        if (! is_array($versions) || ! array_is_list($versions)) {
            throw new DomainException('estimate_generation_ai_price_unknown');
        }
        $selected = null;
        $selectedAt = null;
        foreach ($versions as $candidate) {
            if (! is_array($candidate) || ! is_string($candidate['effective_at'] ?? null)) {
                throw new DomainException('estimate_generation_ai_price_invalid');
            }
            try {
                $effectiveAt = new DateTimeImmutable($candidate['effective_at']);
            } catch (Throwable $exception) {
                throw new DomainException('estimate_generation_ai_price_invalid', previous: $exception);
            }
            if ($effectiveAt > $at || ($selectedAt instanceof DateTimeImmutable && $effectiveAt <= $selectedAt)) {
                continue;
            }
            $selected = $candidate;
            $selectedAt = $effectiveAt;
        }
        if (! is_array($selected)) {
            throw new DomainException('estimate_generation_ai_price_not_effective');
        }
        $selected['source'] = 'contract';

        try {
            return AiPriceSnapshot::fromArray($selected);
        } catch (Throwable $exception) {
            throw new DomainException('estimate_generation_ai_price_invalid', previous: $exception);
        }
    }
}
