<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use Illuminate\Support\Facades\DB;

final class OwnerSnapshotFirstWriter
{
    public static function run(ReportQuery $query, callable $materialize): mixed
    {
        return DB::transaction(function () use ($materialize, $query): mixed {
            if (DB::getDriverName() === 'pgsql') {
                DB::select(
                    'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                    [$query->definition->code.':'.$query->queryHash->value],
                );
            }

            return $materialize();
        }, 3);
    }
}
