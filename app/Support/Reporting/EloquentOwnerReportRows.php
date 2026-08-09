<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class EloquentOwnerReportRows
{
    public function __construct(
        private OwnerReportTokenPayload $tokens,
        private ReportSourceAccessPolicy $sourceAccess,
    ) {}

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $snapshotModel,
        string $rowModel,
        array $allowedSortFields,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
        ?string $projectColumn = null,
        ?string $resourceKind = null,
        ?string $resourceColumn = null,
    ): ReportPage {
        $snapshotRecord = $this->snapshotRecord($context, $snapshot, $snapshotModel);
        $this->assertSort($sort, $allowedSortFields);

        $query = $this->rowQuery(
            $context,
            $snapshot,
            $rowModel,
            $projectColumn,
            $resourceKind,
            $resourceColumn,
        );
        $this->applyCursor($query, $sort, $cursor, $snapshot);
        $this->applyOrder($query, $sort);

        $records = $query->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)
            ->map(fn (Model $row): array => $this->publicRow($row))
            ->values()
            ->all();

        return new ReportPage(
            $rows,
            is_array($snapshotRecord->getAttribute('totals')) ? $snapshotRecord->getAttribute('totals') : [],
            $this->freshness($snapshotRecord),
            $this->quality($snapshotRecord),
            null,
            $limit,
            $hasMore,
            $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $snapshotModel,
        string $rowModel,
        array $allowedSortFields,
        ReportWindowSort $sort,
        int $chunkSize,
        ?string $projectColumn = null,
        ?string $resourceKind = null,
        ?string $resourceColumn = null,
    ): iterable {
        $this->snapshotRecord($context, $snapshot, $snapshotModel);
        $this->assertSort($sort, $allowedSortFields);
        $lastSortValue = null;
        $lastRowKey = null;
        $hasPosition = false;

        do {
            $query = $this->rowQuery(
                $context,
                $snapshot,
                $rowModel,
                $projectColumn,
                $resourceKind,
                $resourceColumn,
            );
            if ($hasPosition) {
                $this->applyPosition($query, $sort, $lastSortValue, (string) $lastRowKey);
            }
            $this->applyOrder($query, $sort);
            $records = $query->limit($chunkSize)->get();

            foreach ($records as $record) {
                yield $this->publicRow($record);
            }

            $count = $records->count();
            $last = $records->last();
            if ($last instanceof Model) {
                $lastSortValue = $last->getAttribute($sort->field);
                $lastRowKey = (string) $last->getAttribute('row_key');
                $hasPosition = true;
            }
        } while ($count === $chunkSize);
    }

    private function snapshotRecord(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $snapshotModel,
    ): Model {
        $this->assertScope($context, $snapshot);

        /** @var Model $model */
        $model = new $snapshotModel;
        $record = $model->newQuery()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->first();

        if (! $record instanceof Model
            || ! hash_equals(
                (string) $record->getAttribute('source_hash'),
                $snapshot->materializedSourceHash->value,
            )) {
            throw new DomainException('Report snapshot is unavailable for the current scope.');
        }

        return $record;
    }

    private function rowQuery(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $rowModel,
        ?string $projectColumn,
        ?string $resourceKind,
        ?string $resourceColumn,
    ): Builder {
        /** @var Model $model */
        $model = new $rowModel;

        $query = $model->newQuery()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->when(
                $projectColumn !== null && $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    $projectColumn,
                    $context->scope->projectIds,
                ),
            );

        if (($resourceKind === null) !== ($resourceColumn === null)) {
            throw new DomainException('Report resource filter mapping is incomplete.');
        }
        if ($resourceKind !== null) {
            $allowedIds = $this->sourceAccess->allowedIds(
                $context->scope->resources,
                $resourceKind,
            );
            if ($allowedIds !== null) {
                $query->whereIn($resourceColumn, $allowedIds);
            }
        }

        return $query;
    }

    private function applyCursor(
        Builder $query,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
    ): void {
        if ($cursor === null) {
            return;
        }
        if ($cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction
            || ! hash_equals($cursor->sourceHash->value, $snapshot->sourceHash->value)) {
            throw new DomainException('Report cursor identity does not match the requested window.');
        }

        $position = $this->tokens->cursor($cursor->token, $snapshot);
        $this->applyPosition($query, $sort, $position['sort_value'], $position['row_key']);
    }

    private function applyPosition(
        Builder $query,
        ReportWindowSort $sort,
        mixed $sortValue,
        string $rowKey,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        if ($sortValue === null) {
            $query->whereNull($sort->field)
                ->where('row_key', $operator, $rowKey);

            return;
        }
        $query->where(function (Builder $builder) use ($sort, $sortValue, $rowKey, $operator): void {
            $builder->where($sort->field, $operator, $sortValue)
                ->orWhere(function (Builder $tie) use ($sort, $sortValue, $rowKey, $operator): void {
                    $tie->where($sort->field, $sortValue)
                        ->where('row_key', $operator, $rowKey);
                })
                ->orWhereNull($sort->field);
        });
    }

    private function applyOrder(Builder $query, ReportWindowSort $sort): void
    {
        $query->orderByRaw("CASE WHEN {$sort->field} IS NULL THEN 1 ELSE 0 END ASC")
            ->orderBy($sort->field, $sort->direction->value)
            ->orderBy('row_key', $sort->direction->value);
    }

    private function publicRow(Model $row): array
    {
        $values = $row->toArray();
        unset($values['id'], $values['organization_id'], $values['snapshot_id']);

        return $values;
    }

    private function quality(Model $snapshot): ReportQuality
    {
        $rowCount = (int) $snapshot->getAttribute('row_count');
        $gapCount = (int) $snapshot->getAttribute('gap_count');
        $covered = max(0, $rowCount - $gapCount);
        $status = ReportQualityStatus::tryFrom((string) $snapshot->getAttribute('quality_status'))
            ?? ($gapCount === 0 ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL);
        $reconciliation = ReportReconciliationStatus::tryFrom(
            (string) $snapshot->getAttribute('reconciliation_status'),
        ) ?? ReportReconciliationStatus::NOT_APPLICABLE;

        return new ReportQuality(
            $status,
            new ReportCoverage(
                (string) $covered,
                (string) $rowCount,
                $rowCount === 0
                    ? null
                    : (string) BigDecimal::of($covered)->dividedBy(
                        BigDecimal::of($rowCount),
                        8,
                        RoundingMode::HalfUp,
                    ),
            ),
            [],
            $gapCount,
            $gapCount === 0 ? $reconciliation : ReportReconciliationStatus::MISMATCH,
            $gapCount === 0 ? [] : ['source_coverage'],
            [],
        );
    }

    private function freshness(Model $snapshot): ReportFreshnessStatus
    {
        if ((int) $snapshot->getAttribute('gap_count') > 0) {
            return ReportFreshnessStatus::PARTIAL;
        }

        $staleAt = $snapshot->getAttribute('stale_at');

        return $staleAt !== null && new DateTimeImmutable((string) $staleAt) <= new DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function assertSort(ReportWindowSort $sort, array $allowedSortFields): void
    {
        if (! in_array($sort->field, $allowedSortFields, true)) {
            throw new DomainException('Unsupported report sort field.');
        }
    }

    private function assertScope(ReportExecutionContext $context, ReportSnapshotRef $snapshot): void
    {
        if ($context->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()) {
            throw new DomainException('Report scope does not match snapshot scope.');
        }
    }
}
