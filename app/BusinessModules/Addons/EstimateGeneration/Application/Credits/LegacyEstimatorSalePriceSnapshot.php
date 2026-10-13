<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use DomainException;

final readonly class LegacyEstimatorSalePriceSnapshot
{
    /** @param array{source:string,pack_id:string,commercial_price_version:int,unit_minor:int,pack_units_minor:int,pack_amount_minor:int,currency:string} $snapshot */
    private function __construct(public array $snapshot, public int $salePriceMinorPerUnit) {}

    public static function capture(string $packId): self
    {
        $pack = config('ai-assistant-credits.packs.'.$packId);
        $version = config('ai-assistant-credits.price_version');
        $unit = config('ai-assistant-credits.unit_minor');
        if (! is_array($pack) || ! is_int($version) || $version < 1 || $unit !== 100
            || ! is_int($pack['units_minor'] ?? null) || $pack['units_minor'] < 100
            || ! is_int($pack['amount_minor'] ?? null) || $pack['amount_minor'] < 1
            || $pack['amount_minor'] > intdiv(PHP_INT_MAX, 100)
            || ($pack['amount_minor'] * 100) % $pack['units_minor'] !== 0) {
            throw new DomainException('legacy_estimator_sale_price_unavailable');
        }
        $salePrice = intdiv($pack['amount_minor'] * 100, $pack['units_minor']);
        if ($salePrice < 1) {
            throw new DomainException('legacy_estimator_sale_price_unavailable');
        }

        return new self(['source' => 'commercial_credit_pack', 'pack_id' => $packId, 'commercial_price_version' => $version,
            'unit_minor' => 100, 'pack_units_minor' => $pack['units_minor'], 'pack_amount_minor' => $pack['amount_minor'], 'currency' => 'RUB'], $salePrice);
    }

    /** @return array{units_minor:int,rounding_remainder_micro_rub:int} */
    public function convert(int $remainingPaidAmountMinor): array
    {
        if ($remainingPaidAmountMinor < 0 || $remainingPaidAmountMinor > 1_000_000_000_000) {
            throw new DomainException('legacy_estimator_remaining_money_invalid');
        }
        $units = intdiv($remainingPaidAmountMinor * 100, $this->salePriceMinorPerUnit);

        return ['units_minor' => $units, 'rounding_remainder_micro_rub' => $remainingPaidAmountMinor * 10000 - $units * $this->salePriceMinorPerUnit * 100];
    }

    public function fingerprint(): string
    {
        return hash('sha256', CanonicalPipelineJson::encode($this->snapshot));
    }
}
