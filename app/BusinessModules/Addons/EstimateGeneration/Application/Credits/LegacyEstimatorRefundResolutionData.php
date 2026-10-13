<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class LegacyEstimatorRefundResolutionData
{
    public function __construct(public int $organizationId, public int $reconciliationId, public string $requestId,
        public string $expectedSnapshotHash, public int $remainingPaidAmountMinor,
        public string $evidenceReference, public string $evidenceSha256, public string $reason)
    {
        if ($organizationId < 1 || $reconciliationId < 1 || ! Str::isUuid($requestId)
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedSnapshotHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $evidenceSha256) !== 1
            || $remainingPaidAmountMinor < 0 || $remainingPaidAmountMinor > 1_000_000_000_000
            || trim($evidenceReference) === '' || strlen($evidenceReference) > 512
            || trim($reason) === '' || strlen($reason) > 2000
            || preg_match('/[\x00-\x1F]/', $evidenceReference.$reason) === 1) {
            throw new InvalidArgumentException('legacy_estimator_refund_resolution_input_invalid');
        }
    }
}
