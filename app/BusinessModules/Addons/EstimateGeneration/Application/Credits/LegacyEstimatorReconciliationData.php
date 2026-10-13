<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** The remaining value is an explicit financial attestation, never inferred from mixed monthly quota usage. */
final readonly class LegacyEstimatorReconciliationData
{
    public function __construct(public int $organizationId, public int $allocationId, public string $requestId,
        public string $expectedAllocationHash, public string $expectedPurchaseHash, public string $expectedSalePriceHash, public int $remainingPaidAmountMinor,
        public string $salePackId, public string $evidenceReference, public string $evidenceSha256, public string $reason)
    {
        if ($organizationId < 1 || $allocationId < 1 || ! Str::isUuid($requestId)
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedAllocationHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedPurchaseHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedSalePriceHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $evidenceSha256) !== 1
            || $remainingPaidAmountMinor < 0 || $remainingPaidAmountMinor > 1_000_000_000_000
            || ! in_array($salePackId, ['ai-credits-1000', 'ai-credits-5000', 'ai-credits-10000'], true)
            || trim($evidenceReference) === '' || strlen($evidenceReference) > 512
            || trim($reason) === '' || strlen($reason) > 2000
            || preg_match('/[\x00-\x1F]/', $evidenceReference.$reason) === 1) {
            throw new InvalidArgumentException('legacy_estimator_reconciliation_input_invalid');
        }
    }
}
