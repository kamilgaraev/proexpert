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
                ? $this->connection->selectOne("SELECT current_setting('statement_timeout') AS timeout", [], false)->timeout
                : null;
            $this->checkpoint();
            $result = $compute($this->checkpoint(...));
            $this->checkpoint();
            if ($previous !== null) {
                $this->connection->selectOne("SELECT set_config('statement_timeout', ?, true)", [$previous], false);
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
