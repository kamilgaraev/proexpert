<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class NullableReportKeyset
{
    public static function order(
        Builder $builder,
        string $field,
        ReportSortDirection $direction,
    ): Builder {
        self::assertField($field);

        $sqlDirection = $direction === ReportSortDirection::ASC ? 'ASC NULLS LAST' : 'DESC NULLS FIRST';

        return $builder
            ->orderByRaw(sprintf('"%s" %s', $field, $sqlDirection))
            ->orderBy('row_key', $direction->value);
    }

    public static function after(
        Builder $builder,
        string $field,
        ReportSortDirection $direction,
        mixed $lastSortValue,
        string $lastRowKey,
    ): void {
        self::assertField($field);

        $builder->where(static function (Builder $position) use (
            $field,
            $direction,
            $lastSortValue,
            $lastRowKey,
        ): void {
            if ($direction === ReportSortDirection::ASC) {
                if ($lastSortValue === null) {
                    $position->whereNull($field)->where('row_key', '>', $lastRowKey);

                    return;
                }

                $position
                    ->where($field, '>', $lastSortValue)
                    ->orWhereNull($field)
                    ->orWhere(static function (Builder $tie) use ($field, $lastSortValue, $lastRowKey): void {
                        $tie->where($field, $lastSortValue)->where('row_key', '>', $lastRowKey);
                    });

                return;
            }

            if ($lastSortValue === null) {
                $position
                    ->where(static function (Builder $nulls) use ($field, $lastRowKey): void {
                        $nulls->whereNull($field)->where('row_key', '<', $lastRowKey);
                    })
                    ->orWhereNotNull($field);

                return;
            }

            $position
                ->where($field, '<', $lastSortValue)
                ->orWhere(static function (Builder $tie) use ($field, $lastSortValue, $lastRowKey): void {
                    $tie->where($field, $lastSortValue)->where('row_key', '<', $lastRowKey);
                });
        });
    }

    private static function assertField(string $field): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1) {
            throw new InvalidArgumentException('report_keyset_field_invalid');
        }
    }
}
