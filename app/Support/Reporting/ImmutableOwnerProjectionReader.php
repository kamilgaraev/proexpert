<?php

declare(strict_types=1);

namespace App\Support\Reporting;

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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class ImmutableOwnerProjectionReader
{
    private ReportSourceObjectAccessAuthorizer $sourceAccess;

    public function __construct(
        private string $rowModel,
        private string $snapshotModel,
        private array $sortColumns,
        private array $sensitiveColumns = [],
        ?ReportSourceObjectAccessAuthorizer $sourceAccess = null,
    ) {
        if (! is_subclass_of($rowModel, Model::class)
            || ! is_subclass_of($snapshotModel, Model::class)
            || $sortColumns === []
        ) {
            throw new InvalidArgumentException('owner_projection_reader_invalid');
        }
        $this->sourceAccess = $sourceAccess ?? new ReportSourceObjectAccessAuthorizer;
    }

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertSnapshot($context, $snapshot);
        $sortColumn = $this->sortColumns[$sort->field] ?? null;
        if (! is_string($sortColumn)) {
            throw new InvalidArgumentException('owner_projection_sort_invalid');
        }

        $query = $this->rowQuery($context, $snapshot);
        $position = $cursor === null ? null : $this->cursorPosition($cursor);
        if ($position !== null) {
            $this->applyPosition($query, $sortColumn, $sort->direction, $position);
        }

        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $records = $query
            ->orderByRaw($sortColumn.' IS NULL ASC')
            ->orderBy($sortColumn, $direction)
            ->orderBy('row_key', $direction)
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $records = $records->take($limit);
        $rows = [];
        foreach ($records as $record) {
            $rows[] = $this->visiblePayload($context, $record);
        }

        $snapshotRecord = $this->snapshotRecord($context, $snapshot);
        $snapshotTotals = (array) $snapshotRecord->getAttribute('totals');

        return new ReportPage(
            rows: $rows,
            totals: $this->visibleTotals($context, $snapshotTotals),
            freshness: $this->freshness($snapshot),
            quality: $this->quality($snapshotTotals),
            nextCursor: null,
            limit: $limit,
            hasMore: $hasMore,
            sort: $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new InvalidArgumentException('owner_projection_chunk_size_invalid');
        }

        $this->assertSnapshot($context, $snapshot);
        $snapshotRecord = $this->snapshotRecord($context, $snapshot);
        $sortColumn = $this->sortColumns[$sort->field] ?? null;
        if (! is_string($sortColumn)) {
            throw new InvalidArgumentException('owner_projection_sort_invalid');
        }

        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';
        $lastValue = null;
        $lastRowKey = null;

        do {
            $query = $this->rowQuery($context, $snapshot);
            if ($lastRowKey !== null) {
                $this->applyPosition($query, $sortColumn, $sort->direction, [
                    'last_sort_value' => $lastValue,
                    'last_stable_row_key' => $lastRowKey,
                ]);
            }

            $records = $query
                ->orderByRaw($sortColumn.' IS NULL ASC')
                ->orderBy($sortColumn, $direction)
                ->orderBy('row_key', $direction)
                ->limit($chunkSize)
                ->get();

            foreach ($records as $record) {
                $lastValue = $record->getAttribute($sortColumn);
                $lastRowKey = (string) $record->getAttribute('row_key');
                yield [
                    'row_key' => $lastRowKey,
                    'values' => $this->visiblePayload($context, $record),
                    'snapshot_id' => $snapshot->id,
                    'query_hash' => (string) $snapshotRecord->getAttribute('query_hash'),
                    'source_hash' => $snapshot->sourceHash->value,
                ];
            }
        } while ($records->count() === $chunkSize);
    }

    public function findRow(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        string $rowKey,
    ): ?array {
        $this->assertSnapshot($context, $snapshot);
        $record = $this->rowQuery($context, $snapshot)
            ->where('row_key', $rowKey)
            ->first();

        return $record === null ? null : $this->visiblePayload($context, $record);
    }

    public function rowKeyFromToken(string $token): string
    {
        $payload = $this->tokenPayload($token);
        $rowKey = $payload['row_key'] ?? null;
        if (! is_string($rowKey) || $rowKey === '') {
            throw new InvalidArgumentException('owner_projection_drill_token_invalid');
        }

        return $rowKey;
    }

    private function rowQuery(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): Builder {
        $model = $this->rowModel;

        return $model::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function snapshotRecord(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): Model {
        $model = $this->snapshotModel;
        $record = $model::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->first();
        if ($record === null
            || ! hash_equals((string) $record->getAttribute('source_hash'), $snapshot->sourceHash->value)
            || ! hash_equals((string) $record->getAttribute('definition_hash'), $snapshot->definitionHash->value)
        ) {
            throw new InvalidArgumentException('owner_projection_snapshot_missing');
        }

        return $record;
    }

    private function assertSnapshot(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): void {
        if ($context->scope->canonicalIdentity() !== $snapshot->scope->canonicalIdentity()) {
            throw new InvalidArgumentException('owner_projection_scope_mismatch');
        }

        $this->snapshotRecord($context, $snapshot);
    }

    private function visiblePayload(ReportExecutionContext $context, Model $record): array
    {
        $payload = (array) $record->getAttribute('payload');
        $projectId = $payload['project_id'] ?? $record->getAttribute('project_id');
        $this->sourceAccess->assertReferencesAccessible(
            $context,
            (array) $record->getAttribute('source_refs'),
            is_numeric($projectId) ? (int) $projectId : null,
        );
        $payload['row_key'] = (string) $record->getAttribute('row_key');
        if (! $context->visibility->canViewSensitive) {
            foreach ($this->sensitiveColumns as $column) {
                unset($payload[$column]);
            }
        }

        return $payload;
    }

    private function visibleTotals(ReportExecutionContext $context, array $totals): array
    {
        if (! $context->visibility->canViewSensitive) {
            return $this->redact($totals);
        }

        return $totals;
    }

    private function redact(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, $this->sensitiveColumns, true)) {
                unset($value[$key]);

                continue;
            }
            if (is_array($item)) {
                $value[$key] = $this->redact($item);
            }
        }

        return $value;
    }

    private function quality(array $totals): ReportQuality
    {
        $unknown = [];
        $this->collectUnknownMetrics($totals, $unknown);
        $unknown = array_keys($unknown);
        sort($unknown, SORT_STRING);

        return new ReportQuality(
            $unknown === [] ? ReportQualityStatus::COMPLETE : ReportQualityStatus::PARTIAL,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            $unknown,
            [],
        );
    }

    private function collectUnknownMetrics(array $value, array &$unknown): void
    {
        foreach ($value as $key => $item) {
            if ($key === 'unknown_metrics' && is_array($item)) {
                foreach ($item as $metric) {
                    if (is_string($metric)) {
                        $unknown[$metric] = true;
                    }
                }

                continue;
            }
            if (is_array($item)) {
                $this->collectUnknownMetrics($item, $unknown);
            }
        }
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new \DateTimeImmutable
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }

    private function cursorPosition(ReportCursor $cursor): array
    {
        $payload = $this->tokenPayload($cursor->token);
        if (! array_key_exists('last_sort_value', $payload)
            || ! isset($payload['last_stable_row_key'])
            || ! is_string($payload['last_stable_row_key'])
        ) {
            throw new InvalidArgumentException('owner_projection_cursor_invalid');
        }

        return $payload;
    }

    private function tokenPayload(string $token): array
    {
        $encoded = explode('.', $token, 2)[0] ?? '';
        $decoded = base64_decode(
            strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true,
        );
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('owner_projection_token_invalid');
        }

        return $payload;
    }

    private function applyPosition(
        Builder $query,
        string $sortColumn,
        ReportSortDirection $direction,
        array $position,
    ): void {
        $operator = $direction === ReportSortDirection::ASC ? '>' : '<';
        $lastValue = $position['last_sort_value'];
        $lastRowKey = $position['last_stable_row_key'];
        if ($lastValue === null) {
            $query
                ->whereNull($sortColumn)
                ->where('row_key', $operator, $lastRowKey);

            return;
        }

        $query->where(function (Builder $builder) use ($sortColumn, $operator, $lastValue, $lastRowKey): void {
            $builder
                ->where($sortColumn, $operator, $lastValue)
                ->orWhereNull($sortColumn)
                ->orWhere(function (Builder $tie) use ($sortColumn, $operator, $lastValue, $lastRowKey): void {
                    $tie->where($sortColumn, $lastValue)->where('row_key', $operator, $lastRowKey);
                });
        });
    }
}
