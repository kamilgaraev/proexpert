<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowBuiltinPublishedReport;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\CurrencyCode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final readonly class IntercompanyContractFlowOptionsService
{
    public function __construct(
        private HoldingAllocationCheckpointSourceAssembler $sources,
        private IntercompanyContractFlowBuiltinPublishedReport $published,
        private ConnectionInterface $connection,
    ) {}

    /** @return array<string, mixed> */
    public function options(ReportScope $scope, DateTimeImmutable $asOf): array
    {
        $query = new ReportQuery(
            $this->published->definition()->payload(),
            $scope,
            new ReportFilterSet([]),
            [],
            $asOf,
            'ru-RU',
        );

        try {
            $batch = $this->sources->assemble($scope, $query);
        } catch (InvalidArgumentException) {
            return $this->unavailable($asOf, 'source_unavailable');
        }
        if ($batch->gaps !== []) {
            return $this->unavailable($asOf, 'source_incomplete', $batch->coverageStartedAt, $scope);
        }

        $organizationIds = [];
        $projectIds = [];
        $counterpartyIds = [];
        $contractIds = [];
        $workTypeCategories = [];
        $currencies = [];
        foreach ($batch->sources as $source) {
            if (! $source instanceof HoldingAllocationCheckpointSource) {
                return $this->unavailable($asOf, 'source_unavailable');
            }
            $fact = $source->fact;
            if (! in_array($fact->contributorOrganizationId, $batch->hierarchy->organizationIds, true)
                || ! in_array($fact->projectId, $scope->projectIds, true)) {
                return $this->unavailable($asOf, 'source_unavailable');
            }

            $organizationIds[$fact->contributorOrganizationId] = true;
            $projectIds[$fact->projectId] = true;
            $contractIds[$fact->contractId] = true;
            if ($fact->counterpartyOrganizationId !== null) {
                $counterpartyIds[$fact->counterpartyOrganizationId] = true;
            }
            if ($fact->workTypeCategory !== null) {
                $workTypeCategories[$fact->workTypeCategory] = true;
            }
            if ($fact->currency !== null) {
                $currencies[$fact->currency] = true;
            }
        }

        $organizations = $this->organizationOptions(
            array_keys($organizationIds),
            $batch->hierarchy->organizationIds,
        );
        $projects = $this->projectOptions(array_keys($projectIds), $scope->projectIds);
        $counterparties = $this->organizationOptions(array_keys($counterpartyIds));
        $contracts = $this->contractOptions(
            array_keys($contractIds),
            $batch->hierarchy->organizationIds,
        );
        if (count($organizations) !== count($organizationIds)
            || count($projects) !== count($projectIds)
            || count($counterparties) !== count($counterpartyIds)
            || count($contracts) !== count($contractIds)) {
            return $this->unavailable($asOf, 'source_reference_missing');
        }

        $workTypes = $this->workTypeOptions(array_keys($workTypeCategories));
        $currencyOptions = $this->currencyOptions(array_keys($currencies));
        if ($workTypes === null || $currencyOptions === null) {
            return $this->unavailable($asOf, 'source_unavailable');
        }

        $coverageDate = CarbonImmutable::parse($batch->coverageStartedAt)
            ->setTimezone($scope->timezone)
            ->toDateString();

        return [
            'available' => true,
            'reason' => null,
            'as_of' => $this->exactDateTime($asOf),
            'period' => [
                'coverage_started_at' => $this->exactDateTime(
                    new DateTimeImmutable($batch->coverageStartedAt),
                ),
                'default_from' => $coverageDate,
                'default_to' => CarbonImmutable::instance($asOf)
                    ->setTimezone($scope->timezone)
                    ->toDateString(),
            ],
            'organizations' => $organizations,
            'projects' => $projects,
            'counterparties' => $counterparties,
            'work_type_categories' => $workTypes,
            'contracts' => $contracts,
            'currencies' => $currencyOptions,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>|null  $allowedIds
     * @return list<array{id:int,name:string}>
     */
    private function organizationOptions(array $ids, ?array $allowedIds = null): array
    {
        if ($ids === []) {
            return [];
        }
        if ($allowedIds !== null && array_diff($ids, $allowedIds) !== []) {
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
        if ($ids === []) {
            return [];
        }
        if (array_diff($ids, $allowedIds) !== []) {
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
    private function contractOptions(array $ids, array $organizationIds): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->table('contracts')
            ->whereIn('id', $ids)
            ->whereIn('organization_id', $organizationIds)
            ->whereNull('deleted_at')
            ->get(['id', 'number', 'subject']);
        $options = [];
        foreach ($rows as $row) {
            $number = trim((string) $row->number);
            $subject = trim((string) $row->subject);
            if ($number === '') {
                continue;
            }
            $options[] = [
                'id' => (int) $row->id,
                'name' => $subject === '' ? $number : $number.' — '.$subject,
            ];
        }

        return $this->sorted($options);
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{id:string,name:string}>|null
     */
    private function workTypeOptions(array $codes): ?array
    {
        $options = [];
        foreach ($codes as $code) {
            $workType = ContractWorkTypeCategoryEnum::tryFrom($code);
            if ($workType === null) {
                return null;
            }
            $options[] = [
                'id' => $workType->value,
                'name' => trans_message('contract.work_type_category.'.$workType->value, locale: 'ru'),
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
        DateTimeImmutable $asOf,
        string $reason,
        ?string $coverageStartedAt = null,
        ?ReportScope $scope = null,
    ): array {
        $coverageDate = $coverageStartedAt !== null && $scope !== null
            ? CarbonImmutable::parse($coverageStartedAt)->setTimezone($scope->timezone)->toDateString()
            : null;

        return [
            'available' => false,
            'reason' => $reason,
            'as_of' => $this->exactDateTime($asOf),
            'period' => [
                'coverage_started_at' => $coverageStartedAt === null
                    ? null
                    : $this->exactDateTime(new DateTimeImmutable($coverageStartedAt)),
                'default_from' => $coverageDate,
                'default_to' => $scope === null
                    ? null
                    : CarbonImmutable::instance($asOf)->setTimezone($scope->timezone)->toDateString(),
            ],
            'organizations' => [],
            'projects' => [],
            'counterparties' => [],
            'work_type_categories' => [],
            'contracts' => [],
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
