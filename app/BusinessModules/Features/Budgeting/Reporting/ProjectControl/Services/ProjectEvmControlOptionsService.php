<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceIdentity;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Exceptions\ProjectControlSourceGapException;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlBuiltinPublishedReport;
use App\Enums\CurrencyCode;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class ProjectEvmControlOptionsService
{
    public function __construct(
        private ProjectControlSourceAssembler $sources,
        private ProjectEvmControlBuiltinPublishedReport $published,
        private ConnectionInterface $connection,
    ) {}

    /** @return array<string, mixed> */
    public function options(ReportScope $scope, DateTimeImmutable $asOf): array
    {
        $query = new ReportQuery(
            $this->published->definition()->payload(),
            $scope,
            new ReportFilterSet(['status_date' => $asOf->format('Y-m-d')]),
            [],
            $asOf,
            'ru-RU',
        );

        try {
            $source = $this->sources->assemble($scope, $query);
        } catch (ProjectControlSourceGapException) {
            return $this->unavailable($asOf, 'source_incomplete');
        } catch (InvalidArgumentException) {
            return $this->unavailable($asOf, 'source_unavailable');
        }

        $identity = $source['identity'] ?? null;
        $rows = $source['rows'] ?? null;
        if (! $identity instanceof ProjectControlSourceIdentity || ! is_array($rows)) {
            return $this->unavailable($asOf, 'source_unavailable');
        }

        $taskWbs = [];
        $wbsCodes = [];
        $contractorIds = [];
        $costCenterIds = [];
        $currencies = [];
        foreach ($rows as $row) {
            if (! $row instanceof ProjectControlSourceRow) {
                return $this->unavailable($asOf, 'source_unavailable');
            }
            $taskWbs[$row->taskId] = $row->wbsCode;
            if ($row->wbsCode !== null && trim($row->wbsCode) !== '') {
                $wbsCodes[trim($row->wbsCode)] = true;
            }
            if ($row->contractorId !== null) {
                $contractorIds[$row->contractorId] = true;
            }
            if ($row->costCenterId !== null) {
                $costCenterIds[$row->costCenterId] = true;
            }
            $currencies[$row->amounts->currency] = true;
        }

        $tasks = $this->tasks($scope, $identity->scheduleId, $taskWbs);
        $contractors = $this->namedOptions(
            'contractors',
            $scope->organizationId,
            array_keys($contractorIds),
        );
        $costCenters = $this->namedOptions(
            'responsibility_centers',
            $scope->organizationId,
            array_keys($costCenterIds),
            true,
        );
        if (count($tasks) !== count($taskWbs)
            || count($contractors) !== count($contractorIds)
            || count($costCenters) !== count($costCenterIds)
        ) {
            return $this->unavailable($asOf, 'source_reference_missing');
        }

        $wbs = array_map(
            static fn (string $code): array => ['id' => $code, 'name' => $code],
            array_keys($wbsCodes),
        );
        $currencyLabels = CurrencyCode::options();
        $currencyOptions = [];
        foreach (array_keys($currencies) as $code) {
            if (! isset($currencyLabels[$code])) {
                return $this->unavailable($asOf, 'source_unavailable');
            }
            $currencyOptions[] = ['id' => $code, 'name' => $currencyLabels[$code]];
        }
        $baseline = $this->baseline($scope, $identity);
        $wipVersion = $this->wipVersion($scope, $identity);
        if ($baseline === null || $wipVersion === null) {
            return $this->unavailable($asOf, 'source_reference_missing');
        }

        return [
            'available' => true,
            'reason' => null,
            'as_of' => $this->exactDateTime($asOf),
            'status_date' => $asOf->format('Y-m-d'),
            'baseline' => $baseline,
            'wip_version' => $wipVersion,
            'tasks' => $tasks,
            'wbs' => $this->sorted($wbs),
            'contractors' => $contractors,
            'cost_centers' => $costCenters,
            'currencies' => $this->sorted($currencyOptions),
        ];
    }

    /**
     * @param  array<int, ?string>  $taskWbs
     * @return list<array{id:int,name:string}>
     */
    private function tasks(ReportScope $scope, int $scheduleId, array $taskWbs): array
    {
        if ($taskWbs === []) {
            return [];
        }
        $projectId = count($scope->projectIds) === 1 ? $scope->projectIds[0] : null;
        if ($projectId === null) {
            return [];
        }
        $rows = $this->connection->table('schedule_tasks as task')
            ->join('project_schedules as schedule', function ($join): void {
                $join->on('schedule.id', '=', 'task.schedule_id')
                    ->on('schedule.organization_id', '=', 'task.organization_id');
            })
            ->where('task.organization_id', $scope->organizationId)
            ->where('task.schedule_id', $scheduleId)
            ->where('schedule.project_id', $projectId)
            ->whereIn('task.id', array_keys($taskWbs))
            ->get(['task.id', 'task.name']);

        $options = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->name);
            if ($name === '') {
                continue;
            }
            $wbs = $taskWbs[(int) $row->id] ?? null;
            $options[] = [
                'id' => (int) $row->id,
                'name' => $wbs === null ? $name : $wbs.' — '.$name,
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{id:int,name:string}>
     */
    private function namedOptions(string $table, int $organizationId, array $ids, bool $withCode = false): array
    {
        if ($ids === []) {
            return [];
        }
        $columns = $withCode ? ['id', 'name', 'code'] : ['id', 'name'];
        $rows = $this->connection->table($table)
            ->where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->get($columns);

        $options = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->name);
            if ($name === '') {
                continue;
            }
            $code = $withCode ? trim((string) $row->code) : '';
            $options[] = [
                'id' => (int) $row->id,
                'name' => $code === '' ? $name : $code.' — '.$name,
            ];
        }

        return $this->sorted($options);
    }

    /** @return array<string, mixed>|null */
    private function baseline(ReportScope $scope, ProjectControlSourceIdentity $identity): ?array
    {
        $row = $this->connection->table('project_control_baseline_versions')
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', $identity->projectId)
            ->where('schedule_id', $identity->scheduleId)
            ->where('version_number', $identity->baselineVersion)
            ->first(['id', 'version_number', 'approved_at']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'version_number' => (int) $row->version_number,
            'approved_at' => (new DateTimeImmutable((string) $row->approved_at))->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed>|null */
    private function wipVersion(ReportScope $scope, ProjectControlSourceIdentity $identity): ?array
    {
        $value = str_starts_with($identity->wipVersion, 'wip_forecast:')
            ? substr($identity->wipVersion, strlen('wip_forecast:'))
            : '';
        if ($value === '') {
            return null;
        }
        $query = $this->connection->table('wip_forecast_versions')
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', $identity->projectId);
        if (ctype_digit($value)) {
            $query->where('id', (int) $value);
        } else {
            $query->where('uuid', $value);
        }
        $row = $query->first(['id', 'uuid', 'name', 'version_number', 'status', 'as_of_date']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'uuid' => (string) $row->uuid,
            'name' => (string) $row->name,
            'version_number' => (int) $row->version_number,
            'status' => (string) $row->status,
            'as_of_date' => (string) $row->as_of_date,
        ];
    }

    /** @param list<array{id:int|string,name:string}> $items */
    private function sorted(array $items): array
    {
        usort($items, static fn (array $left, array $right): int => strnatcasecmp(
            (string) $left['name'],
            (string) $right['name'],
        ));

        return $items;
    }

    /** @return array<string, mixed> */
    private function unavailable(DateTimeImmutable $asOf, string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'as_of' => $this->exactDateTime($asOf),
            'status_date' => $asOf->format('Y-m-d'),
            'baseline' => null,
            'wip_version' => null,
            'tasks' => [],
            'wbs' => [],
            'contractors' => [],
            'cost_centers' => [],
            'currencies' => [],
        ];
    }

    private function exactDateTime(DateTimeImmutable $value): string
    {
        return $value->format('u') === '000000'
            ? $value->format(DATE_ATOM)
            : $value->format('Y-m-d\TH:i:s.uP');
    }
}
