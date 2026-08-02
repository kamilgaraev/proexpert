<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use Closure;
use Illuminate\Support\Facades\DB;

final class ReportSnapshotFirstWriter
{
    public static function run(string $identity, Closure $callback): mixed
    {
        return DB::transaction(static function () use ($identity, $callback): mixed {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', [$identity]);
            }

            return $callback();
        });
    }
}
