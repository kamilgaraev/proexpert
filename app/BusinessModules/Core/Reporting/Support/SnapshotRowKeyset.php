<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use Illuminate\Database\Eloquent\Builder;

final class SnapshotRowKeyset
{
    public static function payload(
        ReportCursor $cursor,
        ReportSnapshotRef $snapshot,
        ReportWindowSort $sort,
    ): array {
        $encoded = explode('.', $cursor->token, 2)[0] ?? '';
        $decoded = base64_decode(
            strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4),
            true,
        );
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        $sortValue = $payload['last_sort_value'] ?? null;

        if (! is_array($payload)
            || $cursor->sourceHash->value !== $snapshot->sourceHash->value
            || $cursor->sort->field !== $sort->field
            || $cursor->sort->direction !== $sort->direction
            || ($payload['snapshot_id'] ?? null) !== $snapshot->id
            || ($payload['source_hash'] ?? null) !== $snapshot->sourceHash->value
            || ($payload['sort_field'] ?? null) !== $sort->field
            || ($payload['sort_direction'] ?? null) !== $sort->direction->value
            || (! is_scalar($sortValue) && $sortValue !== null)
            || ! is_string($payload['last_stable_row_key'] ?? null)
            || trim($payload['last_stable_row_key']) === '') {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_CURSOR_INVALID);
        }

        return [
            'last_sort_value' => $sortValue,
            'last_stable_row_key' => $payload['last_stable_row_key'],
        ];
    }

    public static function order(Builder $query, ReportWindowSort $sort): Builder
    {
        $direction = $sort->direction === ReportSortDirection::ASC ? 'asc' : 'desc';

        return $query
            ->orderByRaw(sprintf('%s %s NULLS LAST', $sort->field, $direction))
            ->orderBy('row_key', $direction);
    }

    public static function after(
        Builder $query,
        ReportWindowSort $sort,
        string|int|float|bool|null $value,
        string $rowKey,
    ): void {
        $operator = $sort->direction === ReportSortDirection::ASC ? '>' : '<';
        $query->where(static function (Builder $query) use ($sort, $value, $rowKey, $operator): void {
            if ($value === null) {
                $query->whereNull($sort->field)
                    ->where('row_key', $operator, $rowKey);

                return;
            }

            $query->where($sort->field, $operator, $value)
                ->orWhere(static function (Builder $query) use ($sort, $value, $rowKey, $operator): void {
                    $query->where($sort->field, $value)
                        ->where('row_key', $operator, $rowKey);
                })
                ->orWhereNull($sort->field);
        });
    }
}
