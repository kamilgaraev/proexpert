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
    public function __construct(private OwnerReportTokenPayload $tokens) {}

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
    ): ReportPage {
        $snapshotRecord = $this->snapshotRecord($context, $snapshot, $snapshotModel);
        $this->assertSort($sort, $allowedSortFields);

        $query = $this->rowQuery($context, $snapshot, $rowModel, $projectColumn);
        $this->applyCursor($query, $sort, $cursor, $snapshot);
        $query->orderBy($sort->field, $sort->direction->value)
            ->orderBy('row_key', $sort->direction->value);

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
    ): iterable {
        $this->snapshotRecord($context, $snapshot, $snapshotModel);
        $this->assertSort($sort, $allowedSortFields);
        $offset = 0;

        do {
            $records = $this->rowQuery($context, $snapshot, $rowModel, $projectColumn)
                ->orderBy($sort->field, $sort->direction->value)
                ->orderBy('row_key', $sort->direction->value)
                ->offset($offset)
                ->limit($chunkSize)
                ->get();

            foreach ($records as $record) {
                yield $this->publicRow($record);
            }

            $count = $records->count();
            $offset += $count;
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
            || ! hash_equals((string) $record->getAttribute('source_hash'), $snapshot->sourceHash->value)) {
            throw new DomainException('Report snapshot is unavailable for the current scope.');
        }

        return $record;
    }

    private function rowQuery(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $rowModel,
        ?string $projectColumn,
    ): Builder {
        /** @var Model $model */
        $model = new $rowModel;

        return $model->newQuery()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->when(
                $projectColumn !== null && $context->scope->projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn(
                    $projectColumn,
                    $context->scope->projectIds,
                ),
            );
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

        $position = $this->tokens->cursor($cursor->token, $snapshot);
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        $query->where(function (Builder $builder) use ($sort, $position, $operator): void {
            $builder->where($sort->field, $operator, $position['sort_value'])
                ->orWhere(function (Builder $tie) use ($sort, $position, $operator): void {
                    $tie->where($sort->field, $position['sort_value'])
                        ->where('row_key', $operator, $position['row_key']);
                });
        });
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
