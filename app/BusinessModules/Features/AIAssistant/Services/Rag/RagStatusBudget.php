<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use Illuminate\Database\Connection;

final class RagStatusBudget
{
    private readonly int $deadline;

    public function __construct(private readonly Connection $connection, int $milliseconds = 1500)
    {
        $this->deadline = hrtime(true) + $milliseconds * 1_000_000;
    }

    public function run(callable $compute): array
    {
        return $this->connection->transaction(function () use ($compute): array {
            $previous = $this->connection->getDriverName() === 'pgsql'
                ? $this->connection->selectOne("SELECT current_setting('statement_timeout') AS timeout, current_setting('jit') AS jit, current_setting('work_mem') AS work_mem, (SELECT setting::int FROM pg_settings WHERE name = 'work_mem') AS work_mem_kb", [], false)
                : null;
            $this->checkpoint();
            if ($previous !== null) {
                $this->connection->statement('SET LOCAL jit = off');
                if ((int) $previous->work_mem_kb < 16384) { $this->connection->statement("SET LOCAL work_mem = '16MB'"); }
            }
            $result = $compute($this->checkpoint(...));
            $this->checkpoint();
            if ($previous !== null) {
                $this->connection->selectOne("SELECT set_config('statement_timeout', ?, true), set_config('jit', ?, true), set_config('work_mem', ?, true)", [$previous->timeout, $previous->jit, $previous->work_mem], false);
            }

            return $result;
        }, 1);
    }

    public function checkpoint(): void
    {
        $remaining = $this->remainingMilliseconds();
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->statement('SET LOCAL statement_timeout = '.$remaining);
        }
    }

    public function checkDeadline(): void
    {
        $this->remainingMilliseconds();
    }

    private function remainingMilliseconds(): int
    {
        $remaining = (int) floor(($this->deadline - hrtime(true)) / 1_000_000);
        if ($remaining <= 0) {
            throw new RagStatusBudgetExceeded;
        }

        return $remaining;
    }
}
