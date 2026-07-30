<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Temporal;

use DomainException;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final class TemporalOwnerFactLease
{
    private bool $released = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly array $tables,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $failures = [];
        foreach (array_reverse($this->tables) as $table) {
            try {
                $this->connection->statement("DROP TABLE IF EXISTS pg_temp.\"{$table}\"");
            } catch (Throwable $exception) {
                $failures[] = $table.':'.$exception::class.':'.$exception->getCode();
            }
        }
        $this->released = true;
        if ($failures !== []) {
            throw new DomainException(
                'REPORT_TEMPORAL_OWNER_FACT_CLEANUP_FAILED:'.implode(',', $failures),
            );
        }
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
        }
    }
}
