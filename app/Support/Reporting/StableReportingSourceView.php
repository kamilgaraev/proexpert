<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class StableReportingSourceView
{
    public function capture(callable $capture, int $attempts = 3): mixed
    {
        if ($attempts < 1 || $attempts > 10) {
            throw new InvalidArgumentException('report_stable_source_attempts_invalid');
        }
        if (DB::transactionLevel() > 0) {
            throw new InvalidArgumentException('report_stable_source_nested_transaction');
        }

        return DB::transaction(function () use ($capture): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return $capture();
        }, $attempts);
    }
}
