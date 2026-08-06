<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Services\Reports\SettlementAgingPolicy;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementExposureRow;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;

final readonly class ContractSettlementCalculator
{
    public const FORMULA_VERSION = 'contracts.settlement-exposure.v2';

    public function calculate(
        ContractSettlementInput $input,
        SettlementAgingPolicy $agingPolicy,
    ): ContractSettlementExposureRow {
        $settlement = $input->acceptedMinor - $input->cashMinor;
        $unperformed = max(0, $input->effectiveMinor - $input->acceptedMinor);
        $unpaid = max(0, $settlement);
        $bucket = $input->dueAt === null
            ? 'due_date_missing'
            : $agingPolicy->bucket($input->dueAt, $input->asOf)->value;

        return new ContractSettlementExposureRow(
            contractId: $input->contractId,
            allocationId: $input->allocationId,
            projectId: $input->projectId,
            partyId: $input->partyId,
            partyType: $input->partyType,
            partyLabel: $input->partyLabel,
            direction: $input->direction,
            currency: $input->currency,
            effectiveMinor: $input->effectiveMinor,
            acceptedMinor: $input->acceptedMinor,
            cashMinor: $input->cashMinor,
            settlementMinor: $settlement,
            unperformedExposureMinor: $unperformed,
            unpaidExposureMinor: $unpaid,
            agingBucket: $bucket,
            sourceRefs: $input->sourceRefs,
        );
    }
}
