<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceBuiltinPublishedReport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\CurrencyCode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class HoldingPerformanceOptionsService
{
    public function __construct(
        private HoldingAllocationCheckpointSourceAssembler $sources,
        private HoldingPerformanceImmutableEventSource $events,
        private HoldingPerformanceOptionDimensionQuery $optionDimensions,
        private HoldingPerformanceBuiltinPublishedReport $published,
        private ConnectionInterface $connection,
    ) {}

    /** @return array<string, mixed> */
    public function options(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        ?string $periodFrom = null,
        ?string $periodTo = null,
    ): array {
        if ($this->connection->transactionLevel() > 0) {
            return $this->optionsWithinStableView($scope, $asOf, $periodFrom, $periodTo);
        }

        return $this->connection->transaction(function () use (
            $scope,
            $asOf,
            $periodFrom,
            $periodTo,
        ): array {
            if ($this->connection->getDriverName() === 'pgsql') {
                $this->connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $this->optionsWithinStableView($scope, $asOf, $periodFrom, $periodTo);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function optionsWithinStableView(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        ?string $periodFrom,
        ?string $periodTo,
    ): array {
        $coverageStartedAt = null;
        try {
            $coverageStartedAt = $this->events->coverageStartedAt(
                $this->sources->coverageStartedAt($asOf),
                $scope->timezone,
            );
            $coverageDate = $this->localDate($coverageStartedAt, $scope);
            $selectedFrom = $periodFrom ?? $coverageDate;
            $selectedTo = $periodTo ?? $this->localDate($asOf, $scope);
            $filters = [
                'period_from' => $selectedFrom,
                'period_to' => $selectedTo,
            ];
            $this->events->assertPeriodCovered($filters, $coverageStartedAt, $scope->timezone);
            $query = new ReportQuery(
                $this->published->definition()->payload(),
                $scope,
                new ReportFilterSet($filters),
                [],
                $asOf,
                'ru-RU',
            );
            $openingBoundary = $this->sources->openingBoundary($query);
            $batch = $this->sources->assembleOpeningState($scope, $query, $openingBoundary);
            $recordedCutoff = now()->toImmutable();
            $eventDimensions = $this->optionDimensions->resolve(
                $batch->hierarchy->holdingId,
                $batch->hierarchy->organizationIds,
                $scope->projectIds,
                $coverageStartedAt,
                $asOf,
                $recordedCutoff,
            );
        } catch (InvalidArgumentException) {
            return $this->unavailable($scope, $asOf, $coverageStartedAt, $periodFrom, $periodTo);
        }
        if ($batch->gaps !== [] || ! $eventDimensions['complete']) {
            return $this->unavailable(
                $scope,
                $asOf,
                $coverageStartedAt,
                $selectedFrom,
                $selectedTo,
                'source_incomplete',
            );
        }

        $organizationIds = [];
        $projectIds = [];
        $contractorIds = [];
        $contractStatuses = [];
        $currencies = [];
        foreach ($batch->sources as $source) {
            if (! $source instanceof HoldingAllocationCheckpointSource) {
                return $this->unavailable($scope, $asOf, $coverageStartedAt, $selectedFrom, $selectedTo);
            }
            if (! $this->collectDimensions(
                $source->fact,
                $batch->hierarchy->organizationIds,
                $scope->projectIds,
                $organizationIds,
                $projectIds,
                $contractorIds,
                $contractStatuses,
                $currencies,
            )) {
                return $this->unavailable($scope, $asOf, $coverageStartedAt, $selectedFrom, $selectedTo);
            }
        }

        foreach ($eventDimensions['dimensions'] as $dimension) {
            if ($this->eventFallsWithinPeriod($dimension, $selectedFrom, $selectedTo)
                && ! $this->collectOptionDimension(
                    $dimension['organization_id'],
                    $dimension['project_id'],
                    $dimension['contractor_id'],
                    $dimension['contract_status'],
                    $dimension['currency'],
                    $batch->hierarchy->organizationIds,
                    $scope->projectIds,
                    $organizationIds,
                    $projectIds,
                    $contractorIds,
                    $contractStatuses,
                    $currencies,
                )) {
                return $this->unavailable($scope, $asOf, $coverageStartedAt, $selectedFrom, $selectedTo);
            }
        }

        $organizations = $this->organizationOptions(
            array_keys($organizationIds),
            $batch->hierarchy->organizationIds,
        );
        $projects = $this->projectOptions(array_keys($projectIds), $scope->projectIds);
        $contractors = $this->contractorOptions(
            array_keys($contractorIds),
            $batch->hierarchy->organizationIds,
        );
        if (count($organizations) !== count($organizationIds)
            || count($projects) !== count($projectIds)
            || count($contractors) !== count($contractorIds)) {
            return $this->unavailable(
                $scope,
                $asOf,
                $coverageStartedAt,
                $selectedFrom,
                $selectedTo,
                'source_reference_missing',
            );
        }

        $statuses = $this->statusOptions(array_keys($contractStatuses));
        $currencyOptions = $this->currencyOptions(array_keys($currencies));
        if ($statuses === null || $currencyOptions === null) {
            return $this->unavailable($scope, $asOf, $coverageStartedAt, $selectedFrom, $selectedTo);
        }

        return [
            'available' => true,
            'reason' => null,
            'as_of' => $this->exactDateTime($asOf),
            'period' => $this->period($scope, $asOf, $coverageStartedAt, $selectedFrom, $selectedTo),
            'organizations' => $organizations,
            'projects' => $projects,
            'contractors' => $contractors,
            'contract_statuses' => $statuses,
            'currencies' => $currencyOptions,
        ];
    }

    /**
     * @param  list<int>  $allowedOrganizationIds
     * @param  list<int>  $allowedProjectIds
     * @param  array<int, true>  $organizationIds
     * @param  array<int, true>  $projectIds
     * @param  array<int, true>  $contractorIds
     * @param  array<string, true>  $contractStatuses
     * @param  array<string, true>  $currencies
     */
    private function collectDimensions(
        HoldingAllocationFact $fact,
        array $allowedOrganizationIds,
        array $allowedProjectIds,
        array &$organizationIds,
        array &$projectIds,
        array &$contractorIds,
        array &$contractStatuses,
        array &$currencies,
    ): bool {
        return $this->collectOptionDimension(
            $fact->contributorOrganizationId,
            $fact->projectId,
            $fact->contractorId,
            $fact->contractStatus,
            $fact->currency,
            $allowedOrganizationIds,
            $allowedProjectIds,
            $organizationIds,
            $projectIds,
            $contractorIds,
            $contractStatuses,
            $currencies,
        );
    }

    private function collectOptionDimension(
        int $organizationId,
        int $projectId,
        ?int $contractorId,
        string $contractStatus,
        ?string $currency,
        array $allowedOrganizationIds,
        array $allowedProjectIds,
        array &$organizationIds,
        array &$projectIds,
        array &$contractorIds,
        array &$contractStatuses,
        array &$currencies,
    ): bool {
        if (! in_array($organizationId, $allowedOrganizationIds, true)
            || ! in_array($projectId, $allowedProjectIds, true)) {
            return false;
        }

        $organizationIds[$organizationId] = true;
        $projectIds[$projectId] = true;
        $contractStatuses[$contractStatus] = true;
        if ($contractorId !== null) {
            $contractorIds[$contractorId] = true;
        }
        if ($currency !== null) {
            $currencies[$currency] = true;
        }

        return true;
    }

    private function eventFallsWithinPeriod(
        array $dimension,
        string $periodFrom,
        string $periodTo,
    ): bool {
        return $dimension['recognized_on'] >= $periodFrom && $dimension['recognized_on'] <= $periodTo;
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $allowedIds
     * @return list<array{id:int,name:string}>
     */
    private function organizationOptions(array $ids, array $allowedIds): array
    {
        if ($ids === [] || array_diff($ids, $allowedIds) !== []) {
            return [];
        }

        $rows = $this->connection->table('organizations')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        return $this->namedRows($rows->all());
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $allowedIds
     * @return list<array{id:int,name:string}>
     */
    private function projectOptions(array $ids, array $allowedIds): array
    {
        if ($ids === [] || array_diff($ids, $allowedIds) !== []) {
            return [];
        }

        $rows = $this->connection->table('projects')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        return $this->namedRows($rows->all());
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $organizationIds
     * @return list<array{id:int,name:string}>
     */
    private function contractorOptions(array $ids, array $organizationIds): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->table('contractors')
            ->whereIn('id', $ids)
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        return $this->namedRows($rows->all());
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{id:string,name:string}>|null
     */
    private function statusOptions(array $codes): ?array
    {
        $options = [];
        foreach ($codes as $code) {
            $status = ContractStatusEnum::tryFrom($code);
            if ($status === null) {
                return null;
            }
            $options[] = [
                'id' => $status->value,
                'name' => trans_message('contract.statuses.'.$status->value, locale: 'ru'),
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{id:string,name:string}>|null
     */
    private function currencyOptions(array $codes): ?array
    {
        $labels = CurrencyCode::options();
        $options = [];
        foreach ($codes as $code) {
            if (! isset($labels[$code])) {
                return null;
            }
            $options[] = ['id' => $code, 'name' => $labels[$code]];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<object>  $rows
     * @return list<array{id:int,name:string}>
     */
    private function namedRows(array $rows): array
    {
        $options = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->name);
            if ($name !== '') {
                $options[] = ['id' => (int) $row->id, 'name' => $name];
            }
        }

        return $this->sorted($options);
    }

    /** @param list<array{id:int|string,name:string}> $options */
    private function sorted(array $options): array
    {
        usort($options, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['name'],
            (string) $right['name'],
        ));

        return $options;
    }

    /** @return array<string, mixed> */
    private function unavailable(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        ?DateTimeInterface $coverageStartedAt,
        ?string $selectedFrom,
        ?string $selectedTo,
        string $reason = 'source_unavailable',
    ): array {
        return [
            'available' => false,
            'reason' => $reason,
            'as_of' => $this->exactDateTime($asOf),
            'period' => $this->period($scope, $asOf, $coverageStartedAt, $selectedFrom, $selectedTo),
            'organizations' => [],
            'projects' => [],
            'contractors' => [],
            'contract_statuses' => [],
            'currencies' => [],
        ];
    }

    /** @return array<string, string|null> */
    private function period(
        ReportScope $scope,
        DateTimeImmutable $asOf,
        ?DateTimeInterface $coverageStartedAt,
        ?string $selectedFrom,
        ?string $selectedTo,
    ): array {
        return [
            'coverage_started_at' => $coverageStartedAt === null
                ? null
                : $this->exactDateTime(DateTimeImmutable::createFromInterface($coverageStartedAt)),
            'default_from' => $coverageStartedAt === null
                ? null
                : $this->localDate($coverageStartedAt, $scope),
            'default_to' => $this->localDate($asOf, $scope),
            'selected_from' => $selectedFrom,
            'selected_to' => $selectedTo,
        ];
    }

    private function localDate(DateTimeInterface $value, ReportScope $scope): string
    {
        return CarbonImmutable::instance($value)->setTimezone($scope->timezone)->toDateString();
    }

    private function exactDateTime(DateTimeImmutable $value): string
    {
        return $value->format('u') === '000000'
            ? $value->format(DATE_ATOM)
            : $value->format('Y-m-d\TH:i:s.uP');
    }
}
