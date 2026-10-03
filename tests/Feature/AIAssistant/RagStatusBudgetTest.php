<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudget;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RagStatusBudgetTest extends TestCase
{
    public function test_slow_statement_is_cancelled_and_outer_transaction_is_restored(): void
    {
        $connection = DB::connection();
        $connection->statement('SET LOCAL statement_timeout = 4000');
        $connection->statement('SET LOCAL jit = on');
        $level = $connection->transactionLevel();

        try {
            (new RagStatusBudget($connection, 500))->run(function (callable $checkpoint) use ($connection): array {
                $checkpoint();
                $connection->select('SELECT pg_sleep(1)');

                return [];
            });
            $this->fail('Slow SQL must be cancelled');
        } catch (QueryException $exception) {
            $this->assertSame('57014', $exception->errorInfo[0]);
        }

        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame('4s', $connection->selectOne("SELECT current_setting('statement_timeout') AS timeout")->timeout);
        $this->assertSame('on', $connection->selectOne("SELECT current_setting('jit') AS jit")->jit);
        $this->assertSame(1, (int) $connection->selectOne('SELECT 1 AS value')->value);
    }

    public function test_success_restores_existing_timeout_inside_outer_transaction(): void
    {
        $connection = DB::connection();
        $connection->statement('SET LOCAL statement_timeout = 4000');
        $level = $connection->transactionLevel();
        $result = (new RagStatusBudget($connection, 500))->run(function (callable $checkpoint) use ($connection): array {
            $checkpoint();
            $timeout = $connection->selectOne("SELECT setting::int AS timeout FROM pg_settings WHERE name = 'statement_timeout'")->timeout;
            $this->assertGreaterThan(0, (int) $timeout);
            $this->assertLessThanOrEqual(500, (int) $timeout);

            return ['source_count' => 1];
        });

        $this->assertSame(['source_count' => 1], $result);
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertSame('4s', $connection->selectOne("SELECT current_setting('statement_timeout') AS timeout")->timeout);
    }

    public function test_total_budget_expires_between_individually_fast_statements(): void
    {
        $connection = DB::connection();
        $connection->statement('SET LOCAL statement_timeout = 4000');
        $connection->statement('SET LOCAL jit = on');
        try {
            (new RagStatusBudget($connection, 200))->run(function (callable $checkpoint) use ($connection): array {
                $connection->select('SELECT 1');
                usleep(250_000);
                $checkpoint();

                return [];
            });
            $this->fail('Total budget must expire');
        } catch (RagStatusBudgetExceeded) {
            $this->assertSame('4s', $connection->selectOne("SELECT current_setting('statement_timeout') AS timeout")->timeout);
            $this->assertSame('on', $connection->selectOne("SELECT current_setting('jit') AS jit")->jit);
        }
    }

    public function test_short_status_queries_skip_jit_and_restore_the_callers_setting(): void
    {
        $connection = DB::connection();
        $connection->statement('SET LOCAL jit_above_cost = 0');

        foreach (['on', 'off'] as $previous) {
            $connection->statement('SET LOCAL jit = '.$previous);
            $result = (new RagStatusBudget($connection, 500))->run(function (callable $checkpoint) use ($connection): array {
                $checkpoint();
                $this->assertSame('off', $connection->selectOne("SELECT current_setting('jit') AS jit")->jit);
                $plan = json_decode($connection->selectOne('EXPLAIN (FORMAT JSON) SELECT SUM(relpages) FROM pg_class')->{'QUERY PLAN'}, true);
                $this->assertArrayNotHasKey('JIT', $plan[0]);

                return ['source_count' => 1];
            });

            $this->assertSame(['source_count' => 1], $result);
            $this->assertSame($previous, $connection->selectOne("SELECT current_setting('jit') AS jit")->jit);
        }
    }

    public function test_unrelated_database_errors_are_not_converted_to_timeout(): void
    {
        try {
            (new RagStatusBudget(DB::connection(), 500))->run(function (): array {
                DB::select('SELECT 1 / 0');

                return [];
            });
            $this->fail('Database errors must propagate');
        } catch (QueryException $exception) {
            $this->assertSame('22012', $exception->errorInfo[0]);
        }
        $this->assertSame(1, (int) DB::selectOne('SELECT 1 AS value')->value);
    }

    public function test_deadline_only_checkpoint_does_not_issue_sql(): void
    {
        $connection = DB::connection();
        $statements = 0;
        $connection->beforeExecuting(function () use (&$statements): void { $statements++; });
        $budget = new RagStatusBudget($connection, 40);
        $budget->checkDeadline();
        $this->assertSame(0, $statements);
        usleep(60_000);

        $this->expectException(RagStatusBudgetExceeded::class);
        $budget->checkDeadline();
    }
}
