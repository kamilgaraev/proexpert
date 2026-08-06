<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Options;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionBuiltinPublishedReport;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class AcceptedProductionOptionsService
{
    public function __construct(
        private AcceptedProductionOptionsSource $source,
        private AcceptedProductionBuiltinPublishedReport $published,
        private ConnectionInterface $connection,
    ) {}

    /** @return array<string, mixed> */
    public function options(
        ReportScope $scope,
        int $projectId,
        DateTimeImmutable $asOf,
        DateTimeImmutable $periodFrom,
        DateTimeImmutable $periodTo,
    ): array {
        if ($projectId < 1 || ! in_array($projectId, $scope->projectIds, true)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        if ($periodFrom > $periodTo) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        $projectScope = $this->projectScope($scope, $projectId);
        $query = new ReportQuery(
            $this->published->definition()->payload(),
            $projectScope,
            new ReportFilterSet([
                'organization_id' => $projectScope->organizationId,
                'project_id' => $projectId,
                'period_from' => $periodFrom->format('Y-m-d'),
                'period_to' => $periodTo->format('Y-m-d'),
            ]),
            [],
            $asOf,
            'ru-RU',
        );
        $snapshot = $this->source->snapshot($projectScope, $query);
        if (! $snapshot->available) {
            return $this->unavailable($asOf, $periodFrom, $periodTo, $snapshot->reason ?? 'source_unavailable');
        }

        $works = $this->works($projectScope, $projectId, $snapshot->workIds);
        $acts = $this->acts($projectScope, $projectId, $snapshot->actIds);
        $contractors = $this->contractors($projectScope, $snapshot->contractorIds);
        if (count($works) !== count($snapshot->workIds)
            || count($acts) !== count($snapshot->actIds)
            || count($contractors) !== count($snapshot->contractorIds)
        ) {
            return $this->unavailable($asOf, $periodFrom, $periodTo, 'source_reference_missing');
        }

        $units = $this->stringOptions($snapshot->unitCodes);
        $zones = $this->stringOptions($snapshot->zones);
        $statuses = [];
        foreach ($snapshot->statuses as $status) {
            $name = trans_message('reports.options.accepted_production_progress.statuses.'.$status);
            if ($name === 'reports.options.accepted_production_progress.statuses.'.$status) {
                return $this->unavailable($asOf, $periodFrom, $periodTo, 'source_unavailable');
            }
            $statuses[] = ['id' => $status, 'name' => $name];
        }

        return [
            'available' => true,
            'reason' => null,
            'as_of' => $this->exactDateTime($asOf),
            'period_from' => $periodFrom->format('Y-m-d'),
            'period_to' => $periodTo->format('Y-m-d'),
            'works' => $works,
            'acts' => $acts,
            'contractors' => $contractors,
            'units' => $units,
            'zones' => $zones,
            'statuses' => $this->sorted($statuses),
        ];
    }

    private function projectScope(ReportScope $scope, int $projectId): ReportScope
    {
        return new ReportScope(
            $scope->organizationId,
            $scope->holdingOrganizationIds,
            [$projectId],
            $scope->resources,
            $scope->timezone,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id:int,name:string}>
     */
    private function works(ReportScope $scope, int $projectId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->table('completed_works as work')
            ->join('work_types as work_type', function ($join): void {
                $join->on('work_type.id', '=', 'work.work_type_id')
                    ->on('work_type.organization_id', '=', 'work.organization_id');
            })
            ->where('work.organization_id', $scope->organizationId)
            ->where('work.project_id', $projectId)
            ->whereIn('work.id', $ids)
            ->get(['work.id', 'work_type.name as work_type_name']);

        $options = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $name = trim((string) $row->work_type_name);
            $options[] = [
                'id' => $id,
                'name' => $name === ''
                    ? trans_message('reports.options.accepted_production_progress.work', ['id' => $id])
                    : trans_message('reports.options.accepted_production_progress.work_with_name', [
                        'id' => $id,
                        'name' => $name,
                    ]),
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id:int,name:string}>
     */
    private function acts(ReportScope $scope, int $projectId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->table('contract_performance_acts as act')
            ->join('contracts as contract', 'contract.id', '=', 'act.contract_id')
            ->where('contract.organization_id', $scope->organizationId)
            ->where('act.project_id', $projectId)
            ->whereIn('act.id', $ids)
            ->get(['act.id', 'act.act_document_number']);

        $options = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $number = trim((string) $row->act_document_number);
            $options[] = [
                'id' => $id,
                'name' => $number === ''
                    ? trans_message('reports.options.accepted_production_progress.act_by_id', ['id' => $id])
                    : trans_message('reports.options.accepted_production_progress.act', ['number' => $number]),
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id:int,name:string}>
     */
    private function contractors(ReportScope $scope, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->connection->table('contractors')
            ->where('organization_id', $scope->organizationId)
            ->whereIn('id', $ids)
            ->get(['id', 'name']);

        $options = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $name = trim((string) $row->name);
            $options[] = [
                'id' => $id,
                'name' => $name === ''
                    ? trans_message('reports.options.accepted_production_progress.contractor', ['id' => $id])
                    : $name,
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<string>  $values
     * @return list<array{id:string,name:string}>
     */
    private function stringOptions(array $values): array
    {
        return $this->sorted(array_map(
            static fn (string $value): array => ['id' => $value, 'name' => $value],
            $values,
        ));
    }

    /**
     * @template T of int|string
     * @param  list<array{id:T,name:string}>  $items
     * @return list<array{id:T,name:string}>
     */
    private function sorted(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['name'],
            (string) $right['name'],
        ));

        return $items;
    }

    /** @return array<string, mixed> */
    private function unavailable(
        DateTimeImmutable $asOf,
        DateTimeImmutable $periodFrom,
        DateTimeImmutable $periodTo,
        string $reason,
    ): array {
        return [
            'available' => false,
            'reason' => $reason,
            'as_of' => $this->exactDateTime($asOf),
            'period_from' => $periodFrom->format('Y-m-d'),
            'period_to' => $periodTo->format('Y-m-d'),
            'works' => [],
            'acts' => [],
            'contractors' => [],
            'units' => [],
            'zones' => [],
            'statuses' => [],
        ];
    }

    private function exactDateTime(DateTimeImmutable $value): string
    {
        return $value->format('u') === '000000'
            ? $value->format(DATE_ATOM)
            : $value->format('Y-m-d\TH:i:s.uP');
    }
}
