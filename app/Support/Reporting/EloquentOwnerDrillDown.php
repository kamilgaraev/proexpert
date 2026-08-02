<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDrillDownResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final readonly class EloquentOwnerDrillDown
{
    public function __construct(
        private OwnerReportTokenPayload $tokens,
        private ReportSourceAccessPolicy $sourceAccess,
    ) {}

    public function resolve(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportDrillDownRequest $request,
        string $rowModel,
        string $sourceModel,
        string $rowRelationColumn,
        string $sourceRelationColumn,
        array $publicColumns,
        array $additionalRelationColumns = [],
        ?string $sourceResourceKind = null,
        ?string $sourceResourceIdColumn = null,
        ?string $rowProjectIdColumn = 'project_id',
        bool $requiresAudit = false,
        bool $requiresSensitive = false,
        ?string $rowSourceIdsColumn = null,
        ?string $sourceCutoffColumn = null,
        ?string $rowDayColumn = null,
        ?string $sourceOccurredAtColumn = null,
        bool $allowEmptyPinnedSourceIds = false,
    ): ReportDrillDownResult {
        if ($context->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()) {
            throw new DomainException('Report scope does not match snapshot scope.');
        }
        if (! $context->visibility->canView
            || ($requiresAudit && ! $context->visibility->canViewAudit)
            || ($requiresSensitive && ! $context->visibility->canViewSensitive)) {
            throw new DomainException('Report drill-down is unavailable for the current access scope.');
        }

        $rowKey = $this->tokens->drillDownRowKey($request->token, $snapshot);
        /** @var Model $row */
        $row = (new $rowModel)->newQuery()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id)
            ->where('row_key', $rowKey)
            ->firstOrFail();
        $relationId = $row->getAttribute($rowRelationColumn);
        if (! is_int($relationId) && ! ctype_digit((string) $relationId)) {
            throw new DomainException('Report drill-down source identity is invalid.');
        }
        if ($sourceResourceKind !== null) {
            $resourceColumn = $sourceResourceIdColumn ?? $rowRelationColumn;
            $resourceId = $row->getAttribute($resourceColumn);
            $rowProjectId = $rowProjectIdColumn === null
                ? null
                : $row->getAttribute($rowProjectIdColumn);
            if ((! is_int($resourceId) && ! ctype_digit((string) $resourceId))
                || ($rowProjectId !== null && ! is_int($rowProjectId) && ! ctype_digit((string) $rowProjectId))
                || ! $this->sourceAccess->allows(
                    $context->scope->resources,
                    $sourceResourceKind,
                    (int) $resourceId,
                    $rowProjectId === null ? null : (int) $rowProjectId,
                    $context->scope->projectIds,
                )) {
                throw new DomainException('Report drill-down source is outside the authorized resource scope.');
            }
        }

        /** @var Model $source */
        $source = new $sourceModel;
        $query = $source->newQuery()
            ->where('organization_id', $context->scope->organizationId)
            ->where($sourceRelationColumn, (int) $relationId)
            ->orderBy('id');
        foreach ($additionalRelationColumns as $column) {
            $query->where($column, $row->getAttribute($column));
        }
        if ($rowSourceIdsColumn !== null) {
            $sourceIds = $row->getAttribute($rowSourceIdsColumn);
            if (! is_array($sourceIds) || ($sourceIds === [] && ! $allowEmptyPinnedSourceIds)) {
                throw new DomainException('Report drill-down pinned source identities are invalid.');
            }
            $sourceIds === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn('id', $sourceIds);
        }
        if ($sourceCutoffColumn !== null) {
            $cutoff = $snapshot->dimensions['as_of'] ?? null;
            if (! is_string($cutoff) || trim($cutoff) === '') {
                throw new DomainException('Report drill-down cutoff is unavailable.');
            }
            $query->where($sourceCutoffColumn, '<=', $cutoff);
        }
        if ($rowDayColumn !== null || $sourceOccurredAtColumn !== null) {
            if ($rowDayColumn === null || $sourceOccurredAtColumn === null) {
                throw new DomainException('Report drill-down day identity is incomplete.');
            }
            $balanceDate = $row->getAttribute($rowDayColumn);
            if ($balanceDate instanceof \DateTimeInterface) {
                $balanceDate = $balanceDate->format('Y-m-d');
            }
            if (! is_string($balanceDate)) {
                throw new DomainException('Report drill-down day identity is invalid.');
            }
            $dayStart = DateTimeImmutable::createFromFormat('!Y-m-d', $balanceDate, $snapshot->scope->timezone);
            if ($dayStart === false || $dayStart->format('Y-m-d') !== $balanceDate) {
                throw new DomainException('Report drill-down day identity is invalid.');
            }
            $dayEnd = $dayStart->modify('+1 day');
            $query
                ->where($sourceOccurredAtColumn, '>=', $dayStart->setTimezone(new DateTimeZone('UTC')))
                ->where($sourceOccurredAtColumn, '<', $dayEnd->setTimezone(new DateTimeZone('UTC')));
        }

        if ($request->cursor !== null) {
            if (preg_match('/^[1-9][0-9]*$/D', $request->cursor) !== 1) {
                throw new DomainException('Report drill-down cursor is invalid.');
            }
            $query->where('id', '>', (int) $request->cursor);
        }

        $records = $query->limit($request->limit + 1)->get();
        $hasMore = $records->count() > $request->limit;
        $page = $records->take($request->limit);
        $rows = $page->map(function (Model $record) use ($publicColumns): array {
            $serialized = $record->toArray();
            $values = ['row_key' => 'source_'.(string) $record->getKey()];
            foreach ($publicColumns as $column) {
                $values[$column] = $serialized[$column] ?? null;
            }

            return $values;
        })->values()->all();
        $last = $page->last();

        return new ReportDrillDownResult(
            $rows,
            $hasMore && $last instanceof Model ? (string) $last->getKey() : null,
            [],
        );
    }
}
