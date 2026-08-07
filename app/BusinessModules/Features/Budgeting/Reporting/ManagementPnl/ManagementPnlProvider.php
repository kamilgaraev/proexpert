<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDataProvider;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Models\ManagementPnlPolicy;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Readiness\ManagementPnlReadinessProbe;
use DomainException;

final readonly class ManagementPnlProvider implements ReportDataProvider
{
    public function __construct(
        private ManagementPnlProjectionService $projection,
        private ManagementPnlQueryService $query,
        private ManagementPnlReadinessProbe $readiness,
    ) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query, ReportProgress $progress): ReportSnapshotRef
    {
        $this->readiness->assertRunnable($context, $query);
        $record = ManagementPnlPolicy::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('status', 'active')
            ->first();
        if (! $record instanceof ManagementPnlPolicy) {
            throw new DomainException('management_pnl_active_policy_missing');
        }
        $policy = new ManagementAccountingPolicy(
            (string) $record->version,
            (array) $record->classification_rules,
            (array) $record->allocation_rules,
        );
        $snapshot = $this->projection->materialize($context->scope, $query, $policy);
        $progress->advance(100);

        return $snapshot;
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        return $this->query->result($context, $snapshot);
    }
}
