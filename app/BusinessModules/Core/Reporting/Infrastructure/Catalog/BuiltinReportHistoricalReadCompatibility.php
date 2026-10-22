<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHistoricalReadCompatibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureBuiltinPublishedReport;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;

final readonly class BuiltinReportHistoricalReadCompatibility implements ReportHistoricalReadCompatibility
{
    public function __construct(
        private AcceptedProductionBuiltinPublishedReport $accepted,
        private ContractSettlementExposureBuiltinPublishedReport $contract,
    ) {}

    public function allows(ReportDefinition $frozen, ReportDefinition $published): bool
    {
        if ($published->code !== $frozen->code) {
            return false;
        }
        if ($frozen->code === ContractSettlementExposureCandidateContract::CODE) {
            $document = $this->contract->document();
            $factory = new ReportDefinitionFactory;
            if ($document['versions']['renderer'] !== '1.1.0'
                || ! hash_equals($factory->fromManifest($document)->definitionHash->value, $published->definitionHash->value)) {
                return false;
            }
            $document['versions']['renderer'] = '1.0.0';

            return hash_equals($factory->fromManifest($document)->definitionHash->value, $frozen->definitionHash->value);
        }
        if ($frozen->code !== AcceptedProductionCandidateContract::CODE) {
            return false;
        }
        $document = $this->accepted->document();
        if ($document['capabilities'] !== ['supports_subscriptions' => true, 'reproducible_scheduled_snapshot' => true]) {
            return false;
        }
        $factory = new ReportDefinitionFactory;
        if (! hash_equals($factory->fromManifest($document)->definitionHash->value, $published->definitionHash->value)) {
            return false;
        }
        $document['capabilities'] = ['supports_subscriptions' => false, 'reproducible_scheduled_snapshot' => false];

        return hash_equals($factory->fromManifest($document)->definitionHash->value, $frozen->definitionHash->value);
    }
}
