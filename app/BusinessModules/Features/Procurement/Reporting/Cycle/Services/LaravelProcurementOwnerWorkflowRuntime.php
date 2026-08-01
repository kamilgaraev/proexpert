<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Services;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Contracts\ProcurementOwnerWorkflowRuntime;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class LaravelProcurementOwnerWorkflowRuntime implements ProcurementOwnerWorkflowRuntime
{
    public function within(callable $workflow): mixed
    {
        return DB::transaction($workflow);
    }

    public function occurredAt(): DateTimeImmutable
    {
        return now('UTC')->toDateTimeImmutable();
    }

    public function afterCommit(callable $callback): void
    {
        DB::afterCommit($callback);
    }
}
