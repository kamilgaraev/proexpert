<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Queries;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportRowQuery;
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
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessRow;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use JsonException;

final readonly class HandoverReadinessRowQuery implements ReportRowQuery
{
    private const SORTS = ['project_id', 'location_id', 'package_id', 'gate_code', 'due_on', 'row_key'];

    public function page(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        ?ReportCursor $cursor,
        int $limit,
    ): ReportPage {
        $this->assertRequest($context, $snapshot, $sort, $limit);
        $builder = $this->builder($context, $snapshot);
        if ($cursor !== null) {
            [$lastSortValue, $lastRowKey] = $this->cursorPosition($cursor, $snapshot, $sort);
            $this->applyPosition($builder, $sort, $lastSortValue, $lastRowKey);
        }
        $direction = $sort->direction->value;
        $records = $builder
            ->orderBy($sort->field, $direction)
            ->orderBy('row_key', $direction)
            ->limit($limit + 1)
            ->get();
        $hasMore = $records->count() > $limit;
        $rows = $records->take($limit)->map($this->map(...))->values()->all();

        return new ReportPage(
            $rows,
            [],
            $this->freshness($snapshot),
            $this->quality(),
            null,
            $limit,
            $hasMore,
            $sort,
        );
    }

    public function cursor(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $chunkSize,
    ): iterable {
        $this->assertRequest($context, $snapshot, $sort, max(1, min(100, $chunkSize)));
        $direction = $sort->direction->value;
        foreach ($this->builder($context, $snapshot)
            ->orderBy($sort->field, $direction)
            ->orderBy('row_key', $direction)
            ->cursor() as $record) {
            yield $this->map($record);
        }
    }

    private function builder(ReportExecutionContext $context, ReportSnapshotRef $snapshot): Builder
    {
        return HandoverReadinessRow::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('snapshot_id', $snapshot->id);
    }

    private function map(HandoverReadinessRow $row): array
    {
        return [
            'row_key' => (string) $row->row_key,
            'project_id' => (int) $row->project_id,
            'acceptance_scope_id' => (int) $row->acceptance_scope_id,
            'location_id' => $row->location_id === null ? null : (int) $row->location_id,
            'package_id' => $row->package_id === null ? null : (int) $row->package_id,
            'gate_code' => (string) $row->gate_code,
            'due_on' => $row->due_on?->format('Y-m-d'),
            'mandatory_completeness' => (string) $row->mandatory_completeness,
            'document_completeness' => (string) $row->document_completeness,
            'open_hard_blocker_count' => (int) $row->open_hard_blocker_count,
            'attempt_count' => (int) $row->attempt_count,
            'successful_result_count' => (int) $row->successful_result_count,
            'ready' => (bool) $row->ready,
        ];
    }

    private function assertRequest(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
        int $limit,
    ): void {
        if (
            $snapshot->kind !== 'handover_readiness'
            || $context->scope->organizationId !== $snapshot->scope->organizationId
            || !in_array($sort->field, self::SORTS, true)
            || $limit < 1
            || $limit > 100
        ) {
            throw new InvalidArgumentException('handover_readiness_query_invalid');
        }
    }

    private function cursorPosition(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): array {
        try {
            $encoded = explode('.', $cursor->token, 2)[0] ?? '';
            $json = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
            $payload = is_string($json) ? json_decode($json, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $payload = null;
        }
        if (
            !is_array($payload)
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ($payload['sort_field'] ?? null) !== $sort->field
            || ($payload['sort_direction'] ?? null) !== $sort->direction->value
            || !is_string($payload['last_stable_row_key'] ?? null)
        ) {
            throw new InvalidArgumentException('handover_readiness_cursor_invalid');
        }

        return [$payload['last_sort_value'] ?? null, $payload['last_stable_row_key']];
    }

    private function applyPosition(
        Builder $builder,
        ReportWindowSort $sort,
        mixed $lastSortValue,
        string $lastRowKey,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        $builder->where(static function (Builder $position) use ($sort, $lastSortValue, $lastRowKey, $operator): void {
            if ($lastSortValue === null) {
                $position->whereNull($sort->field)->where('row_key', $operator, $lastRowKey);
                return;
            }
            $position
                ->where($sort->field, $operator, $lastSortValue)
                ->orWhere(static function (Builder $tie) use ($sort, $lastSortValue, $lastRowKey, $operator): void {
                    $tie->where($sort->field, $lastSortValue)->where('row_key', $operator, $lastRowKey);
                });
        });
    }

    private function quality(): ReportQuality
    {
        return new ReportQuality(
            ReportQualityStatus::COMPLETE,
            null,
            [],
            0,
            ReportReconciliationStatus::MATCHED,
            [],
            [],
        );
    }

    private function freshness(ReportSnapshotRef $snapshot): ReportFreshnessStatus
    {
        return $snapshot->staleAt !== null && $snapshot->staleAt <= new \DateTimeImmutable()
            ? ReportFreshnessStatus::STALE
            : ReportFreshnessStatus::FRESH;
    }
}
