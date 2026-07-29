<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimMetric;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeExposureFact;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Exceptions\DuplicateChangeClaimLink;
use DomainException;

final readonly class ChangeClaimContingencyFormula
{
    public function summarize(iterable $changeFacts, iterable $contingencyMovements): ChangeClaimMetric
    {
        $currency = null;
        $latest = [];
        foreach ($changeFacts as $fact) {
            if (! $fact instanceof ChangeExposureFact) {
                throw new DomainException('change_exposure_fact_invalid');
            }
            $currency = $this->currency($currency, $fact->currency);
            $current = $latest[$fact->changeRequestId] ?? null;
            if (! $current instanceof ChangeExposureFact || $current->changeVersion < $fact->changeVersion) {
                $latest[$fact->changeRequestId] = $fact;
            }
        }

        $claims = [];
        foreach ($latest as $fact) {
            foreach ($fact->linkedClaims as $claim) {
                if (! is_array($claim) || ! isset($claim['id'], $claim['version'], $claim['amount_minor'])) {
                    throw new DomainException('change_claim_link_invalid');
                }
                $key = $claim['id'].':'.$claim['version'];
                if (isset($claims[$key])) {
                    throw new DuplicateChangeClaimLink('change_claim_link_duplicate');
                }
                $claims[$key] = (int) $claim['amount_minor'];
            }
        }

        $opening = 0;
        $openingCount = 0;
        $allocation = 0;
        $consumption = 0;
        $release = 0;
        $balance = 0;
        foreach ($contingencyMovements as $movement) {
            if (! $movement instanceof ContingencyMovement) {
                throw new DomainException('contingency_movement_invalid');
            }
            $currency = $this->currency($currency, $movement->currency);
            if ($movement->type !== 'opening' && $openingCount === 0) {
                throw new DomainException('contingency_opening_missing');
            }
            match ($movement->type) {
                'opening' => $opening += $movement->amountMinor,
                'allocation' => $allocation += $movement->amountMinor,
                'consumption' => $consumption += $movement->amountMinor,
                'release' => $release += $movement->amountMinor,
                default => throw new DomainException('contingency_movement_type_invalid'),
            };
            if ($movement->type === 'opening' && ++$openingCount > 1) {
                throw new DomainException('contingency_opening_duplicate');
            }
            $balance += $movement->signedMinor();
            if ($balance < 0) {
                throw new DomainException('contingency_balance_negative');
            }
        }
        if ($currency === null) {
            throw new DomainException('change_claim_currency_missing');
        }

        return new ChangeClaimMetric(
            currency: $currency,
            proposedExposureMinor: array_sum(array_map(
                static fn (ChangeExposureFact $fact): int => $fact->proposedMinor,
                $latest,
            )),
            approvedExposureMinor: array_sum(array_map(
                static fn (ChangeExposureFact $fact): int => $fact->approvedMinor ?? 0,
                $latest,
            )),
            linkedClaimMinor: array_sum($claims),
            openingContingencyMinor: $opening,
            allocatedContingencyMinor: $allocation,
            consumedContingencyMinor: $consumption,
            releasedContingencyMinor: $release,
            closingContingencyMinor: $opening + $allocation - $consumption - $release,
        );
    }

    private function currency(?string $current, string $next): string
    {
        if ($current !== null && $current !== $next) {
            throw new DomainException('change_claim_cross_currency_aggregation_forbidden');
        }

        return $next;
    }
}
