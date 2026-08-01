<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Contracts\WorkforceCapacityCohortLock;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityCohortKey;
use Illuminate\Support\Facades\DB;

final readonly class PostgresWorkforceCapacityCohortLock implements WorkforceCapacityCohortLock
{
    public function acquire(WorkforceCapacityCohortKey $key): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [
            'workforce-capacity:'.$key->organizationId,
            $key->identity(),
        ]);
    }
}
