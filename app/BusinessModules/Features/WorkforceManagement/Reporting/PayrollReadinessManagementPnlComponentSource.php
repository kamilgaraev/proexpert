<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Contracts\ManagementPnlComponentSource;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO\ManagementPnlComponentSnapshot;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Models\PayrollReadinessSnapshot;
use DomainException;

final readonly class PayrollReadinessManagementPnlComponentSource implements ManagementPnlComponentSource
{
    public function componentCode(): string
    {
        return 'payroll_readiness';
    }

    public function snapshots(ReportScope $scope, ReportQuery $query): iterable
    {
        [$periodFrom, $periodTo, $scenario] = $this->scope($query);
        $snapshots = PayrollReadinessSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->whereDate('period_from', '>=', $periodFrom)
            ->whereDate('period_to', '<=', $periodTo)
            ->where('locked_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn ($builder) => $builder
                ->where(static fn ($projects) => $projects
                    ->whereNull('project_id')
                    ->orWhereIn('project_id', $scope->projectIds)))
            ->with(['rows' => static fn ($builder) => $builder->orderBy('source_row_id')])
            ->orderBy('id')
            ->get()
            ->groupBy('currency');
        if ($snapshots->isEmpty()) {
            throw new DomainException('management_pnl_payroll_readiness_unavailable');
        }

        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        foreach ($snapshots as $currency => $currencySnapshots) {
            $identities = $currencySnapshots->map(static fn (PayrollReadinessSnapshot $snapshot): array => [
                'id' => (int) $snapshot->id,
                'hash' => (string) $snapshot->source_hash,
                'row_count' => (int) $snapshot->row_count,
                'blocking_issue_count' => (int) $snapshot->blocking_issue_count,
            ])->all();
            $hash = hash('sha256', CanonicalJson::encode($identities));
            $rowCount = (int) $currencySnapshots->sum('row_count');
            $blocking = (int) $currencySnapshots->sum('blocking_issue_count');

            yield new ManagementPnlComponentSnapshot(
                componentCode: $this->componentCode(),
                snapshotId: 'payroll-readiness-'.substr($hash, 0, 40),
                sourceHash: new \App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash($hash),
                formulaVersion: 'workforce.payroll-readiness.v1',
                sourceSchemaVersion: 'payroll-readiness-snapshot.v1',
                periodFrom: $periodFrom,
                periodTo: $periodTo,
                scenario: $scenario,
                currency: (string) $currency,
                facts: [],
                scopeHash: $scopeHash,
                queryHash: $query->queryHash->value,
                definitionHash: $query->definition->definitionHash->value,
                asOf: $query->asOf->format(DATE_ATOM),
                rowCount: $rowCount,
                coverageNumerator: $rowCount,
                coverageDenominator: $rowCount + $blocking,
                warnings: $blocking === 0 ? [] : ['payroll_blocking_issue'],
            );
        }
    }

    private function scope(ReportQuery $query): array
    {
        $periodFrom = $query->filters->values['period_from'] ?? null;
        $periodTo = $query->filters->values['period_to'] ?? null;
        $scenarios = $query->filters->values['scenarios'] ?? null;
        if (! is_string($periodFrom) || ! is_string($periodTo)
            || ! is_array($scenarios) || count($scenarios) !== 1 || ! is_string($scenarios[0])) {
            throw new DomainException('management_pnl_exact_scope_required');
        }

        return [$periodFrom, $periodTo, $scenarios[0]];
    }
}
