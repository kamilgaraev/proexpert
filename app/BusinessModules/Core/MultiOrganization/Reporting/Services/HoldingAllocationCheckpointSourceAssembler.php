<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointBatch;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingHierarchySnapshot;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Enums\Contract\ContractAllocationTypeEnum;
use App\Enums\CurrencyCode;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class HoldingAllocationCheckpointSourceAssembler
{
    private const COVERAGE_SOURCES = [
        HoldingReportingSourceCoverage::CONTRACT_DIMENSIONS,
        HoldingReportingSourceCoverage::ORGANIZATION_HIERARCHY,
        HoldingReportingSourceCoverage::ALLOCATION_DIMENSIONS,
        HoldingReportingSourceCoverage::ALLOCATION_AMOUNTS,
    ];

    public function __construct(
        private HoldingReportingSourceCoverage $coverage,
        private HoldingHierarchyResolver $hierarchies,
        private HoldingAllocationFactProjector $projector,
    ) {}

    public function assemble(ReportScope $scope, ReportQuery $query): HoldingAllocationCheckpointBatch
    {
        $coverage = $this->coverage($query->asOf);
        $hierarchy = $this->hierarchies->resolveAt($scope->organizationId, $query->asOf);
        $authorizedOrganizations = $scope->holdingOrganizationIds;
        $historicalOrganizations = $hierarchy->organizationIds;
        sort($authorizedOrganizations, SORT_NUMERIC);
        sort($historicalOrganizations, SORT_NUMERIC);
        if ($hierarchy->holdingId !== $scope->organizationId
            || $authorizedOrganizations !== $historicalOrganizations) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        $filters = $query->filters->values;
        $projectFilter = $this->integerFilter($filters, 'project_ids');
        $organizationFilter = $this->integerFilter($filters, 'organization_ids');
        $this->assertSubset($projectFilter, $scope->projectIds);
        $this->assertSubset($organizationFilter, $hierarchy->organizationIds);
        $this->assertPeriodCovered($filters, $coverage['started_at'], $scope);

        if ($scope->projectIds === []) {
            return $this->batch($hierarchy, $coverage, [], []);
        }

        $timeline = DB::table('holding_allocation_context_events')
            ->select([
                'id',
                'allocation_id',
                'contract_id',
                'organization_id',
                'project_id',
                'allocation_type',
                'allocated_amount',
                'allocated_percentage',
                'is_resolvable',
                'is_active',
                'observed_at',
                'is_deleted',
                'evidence_hash',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY allocation_id ORDER BY observed_at DESC, id DESC) '
                .'AS timeline_position',
            )
            ->whereIn('organization_id', $hierarchy->organizationIds)
            ->whereIn('project_id', $scope->projectIds)
            ->where('observed_at', '<=', $query->asOf);
        $allocations = DB::query()
            ->fromSub($timeline, 'latest_holding_allocation_context')
            ->where('timeline_position', 1)
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->orderBy('allocation_id')
            ->get();

        $contractIds = $allocations
            ->pluck('contract_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $dimensions = $this->dimensions($contractIds, $query->asOf);
        $sources = [];
        $gaps = [];

        foreach ($allocations as $allocation) {
            $allocationId = (int) $allocation->allocation_id;
            if (! (bool) $allocation->is_resolvable) {
                $gaps[] = $this->gap($allocationId, 'allocation_context');

                continue;
            }
            $dimension = $dimensions[(int) $allocation->contract_id] ?? null;
            if (! is_object($dimension)
                || (bool) $dimension->is_deleted
                || (int) $dimension->organization_id !== (int) $allocation->organization_id) {
                $gaps[] = $this->gap($allocationId, 'contract_dimensions');

                continue;
            }

            try {
                $source = $this->source($scope, $hierarchy, $allocation, $dimension);
            } catch (InvalidArgumentException|MathException) {
                $gaps[] = $this->gap($allocationId, 'allocation_evidence');

                continue;
            }
            if (! $this->matchesFilters($source, $filters)) {
                continue;
            }

            $fact = $this->projector->project($source);
            $sourceHash = hash('sha256', CanonicalJson::encode([
                'fact' => get_object_vars($fact),
                'source_refs' => $source['source_refs'],
                'allocation_evidence' => [
                    'allocated_amount_minor' => $source['allocated_amount_minor'],
                    'allocated_percentage' => $source['allocated_percentage'],
                    'contract_amount_minor' => $source['contract_amount_minor'],
                    'business_effective_at' => (string) $source['business_effective_at'],
                ],
            ]));
            $sources[] = new HoldingAllocationCheckpointSource($fact, $source, $sourceHash);
        }

        return $this->batch($hierarchy, $coverage, $sources, $gaps);
    }

    private function coverage(DateTimeInterface $asOf): array
    {
        $rows = [];
        $startedAt = null;
        foreach (self::COVERAGE_SOURCES as $sourceCode) {
            $row = $this->coverage->assertCovers($sourceCode, $asOf);
            $candidate = CarbonImmutable::parse($row['coverage_started_at']);
            if ($startedAt === null || $candidate->gt($startedAt)) {
                $startedAt = $candidate;
            }
            $rows[] = [
                'source_code' => $sourceCode,
                'coverage_started_at' => $row['coverage_started_at'],
                'evidence_hash' => $row['evidence_hash'],
            ];
        }
        if (! $startedAt instanceof CarbonImmutable) {
            throw new InvalidArgumentException('holding_reporting_context_unavailable');
        }

        return [
            'started_at' => $startedAt,
            'sources' => $rows,
            'hash' => hash('sha256', CanonicalJson::encode($rows)),
        ];
    }

    private function dimensions(array $contractIds, DateTimeInterface $asOf): array
    {
        if ($contractIds === []) {
            return [];
        }
        $timeline = DB::table('holding_contract_dimension_events')
            ->select([
                'id',
                'contract_id',
                'organization_id',
                'contractor_id',
                'counterparty_organization_id',
                'contract_status',
                'work_type_category',
                'total_amount',
                'currency',
                'observed_at',
                'is_deleted',
                'evidence_hash',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY contract_id ORDER BY observed_at DESC, id DESC) '
                .'AS timeline_position',
            )
            ->whereIn('contract_id', $contractIds)
            ->where('observed_at', '<=', $asOf);

        return DB::query()
            ->fromSub($timeline, 'latest_holding_contract_dimension')
            ->where('timeline_position', 1)
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->contract_id)
            ->all();
    }

    private function source(
        ReportScope $scope,
        HoldingHierarchySnapshot $hierarchy,
        object $allocation,
        object $dimension,
    ): array {
        $type = ContractAllocationTypeEnum::tryFrom((string) $allocation->allocation_type)
            ?? throw new InvalidArgumentException('holding_allocation_method_invalid');
        $currency = mb_strtoupper((string) $dimension->currency);
        if (CurrencyCode::tryFrom($currency) === null
            || ! is_numeric($dimension->total_amount)
            || trim((string) $dimension->contract_status) === '') {
            throw new InvalidArgumentException('holding_contract_dimension_invalid');
        }
        $contractAmount = BigDecimal::of((string) $dimension->total_amount);
        if ($contractAmount->isNegative()) {
            throw new InvalidArgumentException('holding_contract_dimension_invalid');
        }

        $allocatedAmountMinor = null;
        $allocatedPercentage = null;
        if ($type === ContractAllocationTypeEnum::FIXED) {
            if (! is_numeric($allocation->allocated_amount)) {
                throw new InvalidArgumentException('holding_allocation_amount_invalid');
            }
            $amount = BigDecimal::of((string) $allocation->allocated_amount);
            if ($amount->isNegative()) {
                throw new InvalidArgumentException('holding_allocation_amount_invalid');
            }
            $allocatedAmountMinor = $this->moneyToMinor($amount);
        } else {
            if (! is_numeric($allocation->allocated_percentage)) {
                throw new InvalidArgumentException('holding_allocation_percentage_invalid');
            }
            $percentage = BigDecimal::of((string) $allocation->allocated_percentage);
            if ($percentage->isNegative() || $percentage->isGreaterThan(100)) {
                throw new InvalidArgumentException('holding_allocation_percentage_invalid');
            }
            $allocatedPercentage = (string) $percentage;
        }

        $allocationObservedAt = CarbonImmutable::parse((string) $allocation->observed_at);
        $dimensionObservedAt = CarbonImmutable::parse((string) $dimension->observed_at);
        $observedAt = $allocationObservedAt->greaterThan($dimensionObservedAt)
            ? $allocationObservedAt
            : $dimensionObservedAt;
        $projectId = (int) $allocation->project_id;

        return [
            'organization_id' => (int) $allocation->organization_id,
            'holding_id' => (int) $hierarchy->holdingId,
            'hierarchy_version' => (string) $hierarchy->version,
            'hierarchy_organization_ids' => $hierarchy->organizationIds,
            'contributor_organization_id' => (int) $allocation->organization_id,
            'counterparty_organization_id' => $dimension->counterparty_organization_id === null
                ? null
                : (int) $dimension->counterparty_organization_id,
            'project_id' => $projectId,
            'contract_id' => (int) $allocation->contract_id,
            'contractor_id' => $dimension->contractor_id === null ? null : (int) $dimension->contractor_id,
            'contract_status' => (string) $dimension->contract_status,
            'work_type_category' => $dimension->work_type_category === null
                ? null
                : (string) $dimension->work_type_category,
            'contract_dimension_hash' => (string) $dimension->evidence_hash,
            'allocation_id' => (int) $allocation->allocation_id,
            'linked_parent_allocation_id' => null,
            'linked_incoming_minor' => null,
            'linked_outgoing_minor' => null,
            'source_type' => 'contract_checkpoint',
            'source_id' => (int) $allocation->allocation_id,
            'source_version' => $this->checkpointVersion(
                (int) $allocation->id,
                (int) $dimension->id,
                $hierarchy->version,
            ),
            'monetary_basis' => 'contracted',
            'allocated_amount_minor' => $allocatedAmountMinor,
            'allocated_percentage' => $allocatedPercentage,
            'contract_amount_minor' => $this->moneyToMinor($contractAmount),
            'currency' => $currency,
            'currency_source' => 'contract_dimension_checkpoint',
            'tax_basis' => 'contract_total',
            'recognized_on' => $observedAt->setTimezone($scope->timezone)->toDateString(),
            'business_effective_at' => $observedAt,
            'source_refs' => [[
                'type' => 'contract_allocation',
                'id' => (int) $allocation->allocation_id,
                'contract_id' => (int) $allocation->contract_id,
                'project_id' => $projectId,
                'version' => (int) $allocation->id,
            ], [
                'type' => 'allocation_context',
                'id' => (int) $allocation->id,
                'hash' => (string) $allocation->evidence_hash,
            ], [
                'type' => 'contract_dimension',
                'id' => (int) $dimension->id,
                'hash' => (string) $dimension->evidence_hash,
            ], [
                'type' => 'organization_hierarchy',
                'hash' => (string) $hierarchy->version,
                'evidence_hashes' => $hierarchy->evidenceHashes,
            ]],
        ];
    }

    private function matchesFilters(array $source, array $filters): bool
    {
        foreach ([
            'project_ids' => 'project_id',
            'organization_ids' => 'contributor_organization_id',
            'counterparty_ids' => 'counterparty_organization_id',
            'contract_ids' => 'contract_id',
        ] as $filter => $field) {
            $values = $this->integerFilter($filters, $filter);
            if ($values !== [] && ! in_array($source[$field], $values, true)) {
                return false;
            }
        }
        foreach ([
            'work_type_categories' => 'work_type_category',
            'currencies' => 'currency',
        ] as $filter => $field) {
            $values = $this->stringFilter($filters, $filter);
            if ($values !== [] && ! in_array((string) $source[$field], $values, true)) {
                return false;
            }
        }
        $recognizedOn = (string) $source['recognized_on'];
        if (isset($filters['period_from'])
            && is_string($filters['period_from'])
            && $recognizedOn < $filters['period_from']) {
            return false;
        }
        if (isset($filters['period_to'])
            && is_string($filters['period_to'])
            && $recognizedOn > $filters['period_to']) {
            return false;
        }

        return true;
    }

    private function integerFilter(array $filters, string $key): array
    {
        $values = $filters[$key] ?? [];
        if ($values === null || $values === []) {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('holding_allocation_filter_invalid');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
                throw new InvalidArgumentException('holding_allocation_filter_invalid');
            }
            $id = (int) $value;
            if ($id < 1) {
                throw new InvalidArgumentException('holding_allocation_filter_invalid');
            }
            $normalized[$id] = $id;
        }

        return array_values($normalized);
    }

    private function stringFilter(array $filters, string $key): array
    {
        $values = $filters[$key] ?? [];
        if ($values === null || $values === []) {
            return [];
        }
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidArgumentException('holding_allocation_filter_invalid');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('holding_allocation_filter_invalid');
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    private function assertSubset(array $requested, array $authorized): void
    {
        if (array_diff($requested, $authorized) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function assertPeriodCovered(array $filters, CarbonImmutable $startedAt, ReportScope $scope): void
    {
        $coverageDate = $startedAt->setTimezone($scope->timezone)->toDateString();
        foreach (['period_from', 'period_to'] as $field) {
            if (! isset($filters[$field])) {
                continue;
            }
            if (! is_string($filters[$field])
                || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $filters[$field]) !== 1) {
                throw new InvalidArgumentException('holding_allocation_period_invalid');
            }
            if ($filters[$field] < $coverageDate) {
                throw new InvalidArgumentException('holding_allocation_period_outside_coverage');
            }
        }
        if (isset($filters['period_from'], $filters['period_to'])
            && $filters['period_from'] > $filters['period_to']) {
            throw new InvalidArgumentException('holding_allocation_period_invalid');
        }
    }

    private function moneyToMinor(BigDecimal $amount): int
    {
        return $amount->multipliedBy(100)->toScale(0, RoundingMode::HalfUp)->toInt();
    }

    private function checkpointVersion(
        int $allocationContextId,
        int $contractDimensionId,
        string $hierarchyVersion,
    ): int
    {
        return (int) hexdec(substr(hash(
            'sha256',
            $allocationContextId.':'.$contractDimensionId.':'.$hierarchyVersion,
        ), 0, 15));
    }

    private function gap(int $allocationId, string $reason): array
    {
        return [
            'kind' => 'holding_allocation_checkpoint',
            'allocation_id' => $allocationId,
            'reason' => $reason,
        ];
    }

    private function batch(
        HoldingHierarchySnapshot $hierarchy,
        array $coverage,
        array $sources,
        array $gaps,
    ): HoldingAllocationCheckpointBatch {
        $sourceHashes = array_map(
            static fn (HoldingAllocationCheckpointSource $source): string => $source->sourceHash,
            $sources,
        );
        $watermark = 'holding-allocation-checkpoint:'.hash('sha256', CanonicalJson::encode([
            'coverage_hash' => $coverage['hash'],
            'hierarchy_version' => $hierarchy->version,
            'source_hashes' => $sourceHashes,
            'gaps' => $gaps,
        ]));

        return new HoldingAllocationCheckpointBatch(
            $hierarchy,
            $coverage['started_at']->toISOString(),
            $sources,
            $gaps,
            $watermark,
        );
    }
}
