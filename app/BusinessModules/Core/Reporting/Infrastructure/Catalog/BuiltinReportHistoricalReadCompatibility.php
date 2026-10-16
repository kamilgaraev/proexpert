<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHistoricalReadCompatibility;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;

final readonly class BuiltinReportHistoricalReadCompatibility implements ReportHistoricalReadCompatibility
{
    public function __construct(private AcceptedProductionBuiltinPublishedReport $accepted) {}

    public function allows(ReportDefinition $frozen, ReportDefinition $published): bool
    {
        if ($frozen->code !== AcceptedProductionCandidateContract::CODE || $published->code !== $frozen->code) {
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
