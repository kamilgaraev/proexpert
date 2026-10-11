<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Exceptions\NormativeContextPinUnavailable;

final readonly class NormativeDatasetPinPolicy
{
    public function __construct(private ApprovedNormativeDatasetLookup $lookup, private NormativePinClock $clock) {}

    public function resolve(?string $requestedVersion): array
    {
        $approvedVersion = $this->lookup->latestApprovedVersion();
        if ($approvedVersion === null) {
            throw new NormativeContextPinUnavailable('Approved normative dataset is unavailable.');
        }
        if ($requestedVersion !== null && $requestedVersion !== '' && ! hash_equals($approvedVersion, $requestedVersion)) {
            throw new NormativeContextPinUnavailable('Requested normative dataset is not approved.');
        }
        $date = $this->clock->now()->format('Y-m-d');

        return ['normative_dataset_version' => $approvedVersion, 'business_date' => $date];
    }

    public function optional(?string $requestedVersion): array
    {
        if ($requestedVersion !== null && $requestedVersion !== '') {
            return $this->resolve($requestedVersion);
        }

        return $this->lookup->latestApprovedVersion() === null ? [] : $this->resolve(null);
    }
}
