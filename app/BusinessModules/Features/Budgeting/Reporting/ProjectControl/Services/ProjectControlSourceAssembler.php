<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Features\Budgeting\Models\WipForecastLine;
use App\BusinessModules\Features\Budgeting\Models\WipForecastVersion;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlAmounts;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceIdentity;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlBaselineVersion;
use App\Support\Reporting\ReportScopedResourceFilter;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ProjectControlSourceAssembler
{
    public function assemble(ReportScope $scope, ReportQuery $query): array
    {
        if (count($scope->projectIds) !== 1) {
            throw new InvalidArgumentException('project_control_single_project_scope_required');
        }
        $projectId = $scope->projectIds[0];
        $scopedTaskIds = (new ReportScopedResourceFilter)->ids(
            $scope,
            ['task', 'schedule_task'],
            [$projectId],
        );
        $this->assertStatusDateFilter($query);
        $baselines = ProjectControlBaselineVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', $projectId)
            ->where('approved_at', '<=', $query->asOf)
            ->orderByDesc('approved_at')
            ->orderByDesc('version_number')
            ->limit(2)
            ->get();
        $baseline = $baselines->first();
        if (! $baseline instanceof ProjectControlBaselineVersion) {
            throw new InvalidArgumentException('project_control_baseline_unavailable');
        }
        if ($baselines->count() > 1
            && (int) $baselines->get(1)->schedule_id !== (int) $baseline->schedule_id
            && $baselines->get(1)->approved_at->equalTo($baseline->approved_at)
        ) {
            throw new InvalidArgumentException('project_control_baseline_ambiguous');
        }

        $version = WipForecastVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('project_id', $projectId)
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where(function ($approved) use ($query): void {
                        $approved
                            ->where('status', 'approved')
                            ->whereNotNull('approved_at')
                            ->where('approved_at', '<=', $query->asOf);
                    })
                    ->orWhere(function ($active) use ($query): void {
                        $active
                            ->where('status', 'active')
                            ->whereNotNull('activated_at')
                            ->where('activated_at', '<=', $query->asOf);
                    });
            })
            ->whereDate('as_of_date', '<=', $query->asOf->format('Y-m-d'))
            ->with(['lines' => static fn ($builder) => $builder
                ->where('project_id', $projectId)
                ->orderBy('id')])
            ->orderByDesc('as_of_date')
            ->orderByDesc('version_number')
            ->first();
        if (! $version instanceof WipForecastVersion
            || trim((string) $version->source_snapshot_hash) === ''
            || $version->lines->isEmpty()
        ) {
            throw new InvalidArgumentException('project_control_wip_version_unavailable');
        }

        $linesByTask = [];
        foreach ($version->lines as $line) {
            $taskId = $this->taskId($line);
            if ($taskId === null || isset($linesByTask[$taskId])) {
                throw new InvalidArgumentException('project_control_wip_task_identity_invalid');
            }
            $linesByTask[$taskId] = $line;
        }

        $payload = (array) $baseline->source_payload;
        $baselineRows = $payload['rows'] ?? null;
        if (! is_array($baselineRows) || ! array_is_list($baselineRows) || $baselineRows === []) {
            throw new InvalidArgumentException('project_control_baseline_payload_invalid');
        }

        $rows = [];
        $baselineTaskIds = [];
        foreach ($baselineRows as $baselineRow) {
            if (! is_array($baselineRow)) {
                throw new InvalidArgumentException('project_control_baseline_row_invalid');
            }
            $taskId = $this->positiveInt($baselineRow['task_id'] ?? null);
            $baselineTaskIds[$taskId] = true;
            $currency = (string) ($baselineRow['currency'] ?? '');
            if (($scopedTaskIds !== null && ! in_array($taskId, $scopedTaskIds, true))
                || ! $this->matches($query->filters->values['project_ids'] ?? [], $projectId)
                || ! $this->matches($query->filters->values['task_ids'] ?? [], $taskId)
                || ! $this->matches($query->filters->values['wbs_ids'] ?? [], $baselineRow['wbs_code'] ?? null)
                || ! $this->matches($query->filters->values['currencies'] ?? [], $currency)
            ) {
                continue;
            }
            $line = $linesByTask[$taskId] ?? null;
            if (! $line instanceof WipForecastLine) {
                throw new InvalidArgumentException('project_control_progress_source_incomplete');
            }
            if ($currency === '' || $currency !== (string) $line->currency) {
                throw new InvalidArgumentException('project_control_currency_mismatch');
            }
            $dimensions = (array) $line->dimensions;
            $contractorId = $this->nullablePositiveInt($dimensions['contractor_id'] ?? null);
            $costCenterId = $this->nullablePositiveInt($dimensions['cost_center_id'] ?? null);
            if (! $this->matches($query->filters->values['contractor_ids'] ?? [], $contractorId)
                || ! $this->matches($query->filters->values['cost_center_ids'] ?? [], $costCenterId)
            ) {
                continue;
            }
            $bacMinor = $this->moneyMinor((string) ($baselineRow['bac'] ?? ''));
            $pvMinor = $this->plannedValueMinor(
                (array) ($baselineRow['baseline_curve'] ?? []),
                $query->asOf,
                $bacMinor,
            );
            $percentComplete = $this->percentage((string) $line->percent_complete);
            $evMinor = $this->proportion($bacMinor, $percentComplete, 1_000_000);
            $sourceRefs = [
                [
                    'type' => 'schedule_task',
                    'id' => $taskId,
                    'project_id' => $projectId,
                ],
                [
                    'type' => 'project_control_baseline',
                    'id' => (int) $baseline->id,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'curve_version' => (string) $baselineRow['baseline_curve_version'],
                ],
                [
                    'type' => 'wip_forecast_line',
                    'id' => (int) $line->id,
                    'project_id' => $projectId,
                    'version_id' => (int) $version->id,
                    'source_snapshot_hash' => (string) $version->source_snapshot_hash,
                ],
                ...$this->sourceRefs((array) $line->source_row_refs),
            ];
            $rows[] = new ProjectControlSourceRow(
                rowKey: implode(':', [$projectId, $taskId, $currency]),
                projectId: $projectId,
                taskId: $taskId,
                wbsCode: isset($baselineRow['wbs_code']) ? (string) $baselineRow['wbs_code'] : null,
                contractorId: $contractorId,
                costCenterId: $costCenterId,
                amounts: new ProjectControlAmounts(
                    bacMinor: $bacMinor,
                    pvMinor: $pvMinor,
                    evMinor: $evMinor,
                    acMinor: $this->moneyMinor((string) $line->ac),
                    approvedEtcMinor: $line->etc === null ? null : $this->moneyMinor((string) $line->etc),
                    currency: $currency,
                ),
                sourceRefs: $sourceRefs,
            );
        }
        foreach ($linesByTask as $taskId => $line) {
            if (isset($baselineTaskIds[$taskId])
                || ($scopedTaskIds !== null && ! in_array($taskId, $scopedTaskIds, true))
                || ! $this->matches($query->filters->values['project_ids'] ?? [], $projectId)
                || ! $this->matches($query->filters->values['task_ids'] ?? [], $taskId)
                || ! $this->matches($query->filters->values['currencies'] ?? [], (string) $line->currency)
            ) {
                continue;
            }
            $dimensions = (array) $line->dimensions;
            if (! $this->matches(
                $query->filters->values['contractor_ids'] ?? [],
                $this->nullablePositiveInt($dimensions['contractor_id'] ?? null),
            ) || ! $this->matches(
                $query->filters->values['cost_center_ids'] ?? [],
                $this->nullablePositiveInt($dimensions['cost_center_id'] ?? null),
            )) {
                continue;
            }

            throw new InvalidArgumentException('project_control_wip_line_without_baseline');
        }

        $isActive = (string) $version->status === 'active';
        $approvedBy = $isActive ? $version->activated_by : $version->approved_by;
        $approvedAt = $isActive ? $version->activated_at : $version->approved_at;
        if ((int) $approvedBy < 1
            || $approvedAt === null
            || $approvedAt->toDateTimeImmutable() > $query->asOf
        ) {
            throw new InvalidArgumentException('project_control_wip_approval_identity_missing');
        }
        $sourceWatermark = (string) $version->source_snapshot_hash;

        return [
            'identity' => new ProjectControlSourceIdentity(
                organizationId: $scope->organizationId,
                projectId: $projectId,
                scheduleId: (int) $baseline->schedule_id,
                baselineVersion: (int) $baseline->version_number,
                statusDate: $query->asOf,
                wipVersion: 'wip_forecast:'.(string) ($version->uuid ?? $version->id),
                progressWatermark: $sourceWatermark,
                actualCostWatermark: $sourceWatermark,
                sourceHash: (string) $baseline->source_hash,
            ),
            'rows' => $rows,
            'approved_by' => (int) $approvedBy,
            'approved_at' => new DateTimeImmutable($approvedAt->format(DATE_ATOM)),
        ];
    }

    private function taskId(WipForecastLine $line): ?int
    {
        $dimensions = (array) $line->dimensions;
        $group = (array) $line->group_values;

        return $this->nullablePositiveInt($dimensions['task_id'] ?? $group['task_id'] ?? null);
    }

    private function plannedValueMinor(array $curve, DateTimeImmutable $statusDate, int $bacMinor): int
    {
        if (! array_is_list($curve) || $curve === []) {
            throw new InvalidArgumentException('project_control_baseline_curve_invalid');
        }
        $value = null;
        $previousDate = null;
        foreach ($curve as $point) {
            if (! is_array($point) || ! is_string($point['date'] ?? null)) {
                throw new InvalidArgumentException('project_control_baseline_curve_invalid');
            }
            $date = new DateTimeImmutable($point['date']);
            if ($previousDate !== null && $date <= $previousDate) {
                throw new InvalidArgumentException('project_control_baseline_curve_order_invalid');
            }
            $previousDate = $date;
            if ($date > $statusDate) {
                continue;
            }
            if (isset($point['planned_value_minor']) && is_int($point['planned_value_minor'])) {
                $value = $point['planned_value_minor'];

                continue;
            }
            if (isset($point['cumulative_percent']) && is_string($point['cumulative_percent'])) {
                $value = $this->proportion(
                    $bacMinor,
                    $this->percentage($point['cumulative_percent']),
                    1_000_000,
                );

                continue;
            }
            throw new InvalidArgumentException('project_control_baseline_curve_value_invalid');
        }

        return $value ?? 0;
    }

    private function moneyMinor(string $value): int
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', trim($value), $matches) !== 1) {
            throw new InvalidArgumentException('project_control_money_invalid');
        }
        $minor = ((int) $matches[2] * 100) + (int) str_pad($matches[3] ?? '', 2, '0');

        return ($matches[1] ?? '') === '-' ? -$minor : $minor;
    }

    private function percentage(string $value): int
    {
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/D', trim($value), $matches) !== 1) {
            throw new InvalidArgumentException('project_control_progress_invalid');
        }
        $scaled = ((int) $matches[1] * 10_000) + (int) str_pad($matches[2] ?? '', 4, '0');
        if ($scaled > 1_000_000) {
            throw new InvalidArgumentException('project_control_progress_invalid');
        }

        return $scaled;
    }

    private function proportion(int $amount, int $numerator, int $denominator): int
    {
        $product = $amount * $numerator;
        $offset = intdiv($denominator, 2);

        return $product < 0
            ? -intdiv(abs($product) + $offset, $denominator)
            : intdiv($product + $offset, $denominator);
    }

    private function positiveInt(mixed $value): int
    {
        $result = $this->nullablePositiveInt($value);
        if ($result === null) {
            throw new InvalidArgumentException('project_control_positive_integer_required');
        }

        return $result;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (int) $value < 1) {
            throw new InvalidArgumentException('project_control_positive_integer_required');
        }

        return (int) $value;
    }

    private function sourceRefs(array $refs): array
    {
        if (! array_is_list($refs)) {
            throw new InvalidArgumentException('project_control_source_refs_invalid');
        }

        return array_values(array_filter($refs, 'is_array'));
    }

    private function assertStatusDateFilter(ReportQuery $query): void
    {
        $statusDate = $query->filters->values['status_date'] ?? null;
        if ($statusDate === null) {
            return;
        }
        if (! is_string($statusDate)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $statusDate) !== 1
            || $statusDate !== $query->asOf->format('Y-m-d')
        ) {
            throw new InvalidArgumentException('project_control_status_date_filter_invalid');
        }
    }

    private function matches(mixed $filter, int|string|null $value): bool
    {
        if ($filter === []) {
            return true;
        }
        if (! is_array($filter) || ! array_is_list($filter) || $value === null) {
            return false;
        }

        return in_array((string) $value, array_map('strval', $filter), true);
    }
}
