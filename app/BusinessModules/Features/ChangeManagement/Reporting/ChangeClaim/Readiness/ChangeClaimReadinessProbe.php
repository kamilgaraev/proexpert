<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Readiness;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimReadinessSnapshot;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimHistoryCheckpoint;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimLink;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeRequestVersion;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeWorkflowEvent;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ContingencyLedgerEntry;
use Illuminate\Database\Eloquent\Builder;

final readonly class ChangeClaimReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'change_claim_contingency'
            && $definition->formulaVersion === 'change-claim-contingency.v1';
    }

    public function assertRunnable(ReportExecutionContext $context, ReportQuery $query): void
    {
        $snapshot = $this->inspect($context->scope, $query);
        if ($snapshot->factCount === 0 || ! $snapshot->historyComplete) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, [
                'report_code' => 'change_claim_contingency',
                'availability' => $snapshot->factCount === 0 ? 'no_data' : 'source_incomplete',
            ]);
        }
    }

    public function inspect(ReportScope $scope, ReportQuery $query): ChangeClaimReadinessSnapshot
    {
        $filters = $query->filters->values;
        $versions = ChangeRequestVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('effective_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->when(isset($filters['period_from']), static fn (Builder $builder) => $builder->whereDate('effective_at', '>=', (string) $filters['period_from']))
            ->when(isset($filters['period_to']), static fn (Builder $builder) => $builder->whereDate('effective_at', '<=', (string) $filters['period_to']))
            ->get();
        $ledger = ContingencyLedgerEntry::query()
            ->where('organization_id', $scope->organizationId)
            ->where('effective_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->when(isset($filters['period_to']), static fn (Builder $builder) => $builder->whereDate('effective_on', '<=', (string) $filters['period_to']))
            ->get();
        $checkpoint = ChangeClaimHistoryCheckpoint::query()
            ->where('organization_id', $scope->organizationId)
            ->where('completed_at', '<=', $query->asOf)
            ->orderByDesc('completed_at')
            ->first();
        $versionIds = $versions->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $claimIds = $versionIds === [] ? [] : ChangeClaimLink::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('change_request_version_id', $versionIds)
            ->pluck('change_claim_id')->map(static fn ($id): int => (int) $id)->all();
        $events = $versions->isEmpty() ? collect() : ChangeWorkflowEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('change_request_id', $versions->pluck('change_request_id'))
            ->where('occurred_at', '<=', $query->asOf)
            ->get();
        $monetaryComplete = $versions->every(static fn ($version): bool => $version->contract_project_allocation_id !== null
            && $version->currency !== null);
        $approvalComplete = $events->where('event_type', 'approve')->every(static function ($event) use ($versions, $ledger): bool {
            if (! $versions->contains(static fn ($version): bool => (int) $version->change_request_id === (int) $event->change_request_id
                && (int) $version->version === (int) $event->version)) {
                return true;
            }

            return $ledger->contains(static fn ($entry): bool => (string) $entry->source_type === 'change_request'
                && (string) $entry->source_id === (string) $event->change_request_id
                && (int) $entry->source_version === (int) $event->version
                && (string) $entry->movement_type === 'consumption');
        });
        $latestByAllocation = $versions->whereNotNull('contract_project_allocation_id')
            ->groupBy(static fn ($version): string => $version->contract_project_allocation_id.':'.$version->currency)
            ->map(static fn ($group) => $group->max('effective_at'));
        $ledgerComplete = $ledger->every(static function ($entry) use ($latestByAllocation): bool {
            $latest = $latestByAllocation->get($entry->contract_project_allocation_id.':'.$entry->currency);

            return $latest !== null && $entry->effective_at <= $latest;
        });

        return new ChangeClaimReadinessSnapshot(
            $versions->count(),
            $checkpoint !== null,
            $checkpoint !== null
                && (int) $checkpoint->unprojectable_legacy_count === 0
                && $monetaryComplete
                && $approvalComplete
                && $ledgerComplete,
            $this->ids([...$versions->pluck('project_id')->all(), ...$ledger->pluck('project_id')->all()]),
            $this->ids($versions->pluck('contract_id')->all()),
            $this->ids([...$versions->pluck('contract_project_allocation_id')->all(), ...$ledger->pluck('contract_project_allocation_id')->all()]),
            $this->ids($versions->pluck('change_request_id')->all()),
            $this->ids($claimIds),
            $this->strings($versions->pluck('status')->all()),
            $this->strings([...$versions->pluck('currency')->all(), ...$ledger->pluck('currency')->all()]),
            $this->strings($versions->pluck('initiator_type')->all()),
            $this->ids($versions->pluck('initiator_user_id')->all()),
            $this->ids($versions->pluck('owner_user_id')->all()),
            $this->strings($versions->pluck('reason')->all()),
            $this->strings($ledger->pluck('source_type')->all()),
        );
    }

    private function ids(array $values): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($values))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function strings(array $values): array
    {
        $strings = array_values(array_unique(array_map('strval', array_filter($values, static fn ($value): bool => $value !== null && $value !== ''))));
        sort($strings, SORT_STRING);

        return $strings;
    }
}
