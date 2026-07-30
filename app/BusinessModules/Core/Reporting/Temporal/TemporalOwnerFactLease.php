<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Temporal;

use Illuminate\Database\ConnectionInterface;

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
        foreach (array_reverse($this->tables) as $table) {
            $this->connection->statement("DROP TABLE IF EXISTS pg_temp.\"{$table}\"");
        }
        $this->released = true;
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
        }
    }
}
