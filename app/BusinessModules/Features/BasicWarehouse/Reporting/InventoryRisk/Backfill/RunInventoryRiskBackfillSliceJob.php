<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Backfill;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class RunInventoryRiskBackfillSliceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 5;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $organizationId, public readonly string $cursor = '')
    {
        $this->onQueue('reports-source-backfill');
    }

    public function handle(InventoryRiskBackfill $backfill): void
    {
        $batch = $backfill->backfillSlice($this->organizationId, $this->cursor, 500);
        if (! $batch->done) {
            self::dispatch($this->organizationId, $batch->nextCursor);
        }
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->expireAfter(900)];
    }

    public function uniqueId(): string
    {
        return $this->organizationId.':'.$this->cursor;
    }
}
