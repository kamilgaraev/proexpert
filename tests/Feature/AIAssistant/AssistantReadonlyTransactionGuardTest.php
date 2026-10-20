<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantReadonlyTransactionGuardTest extends TestCase
{
    private ?AssistantRequestExecutionContext $context = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertStringEndsWith('_testing', DB::connection()->getDatabaseName());
        DB::statement('CREATE TABLE assistant_report_guard_probe (id integer)');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) { DB::rollBack(); }
        $this->context?->restoreDatabaseStatementTimeouts();
        app()->forgetInstance(AssistantRequestExecutionContext::class);
        DB::statement('DROP TABLE assistant_report_guard_probe');
        parent::tearDown();
    }

    public function test_active_assistant_guard_allows_first_report_fence_and_preserves_readonly_and_timeouts(): void
    {
        $this->activate();
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        self::assertSame('repeatable read', DB::selectOne('SHOW transaction_isolation')->transaction_isolation);
        self::assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        self::assertContains(DB::selectOne('SHOW statement_timeout')->statement_timeout, ['5s', '5000ms']);
        try {
            DB::statement('UPDATE assistant_report_guard_probe SET id = id WHERE false');
            self::fail('Readonly reporting transaction must reject writes');
        } catch (QueryException $exception) {
            self::assertSame('25006', $exception->errorInfo[0]);
        }
    }

    public function test_expired_deadline_blocks_fence_without_first_establishing_a_database_snapshot(): void
    {
        $this->activate();
        (new \ReflectionProperty($this->context, 'deadlineNanoseconds'))->setValue($this->context, 0);
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            self::fail('Expired request must stop before the fence');
        } catch (AssistantRequestDeadlineExceeded) {
            self::assertTrue(true);
        }
        app()->forgetInstance(AssistantRequestExecutionContext::class);
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        self::assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
    }

    public function test_queries_after_the_fence_still_observe_the_assistant_statement_budget(): void
    {
        $this->activate();
        DB::beginTransaction();
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        (new \ReflectionProperty($this->context, 'deadlineNanoseconds'))->setValue($this->context, hrtime(true) + 75_000_000);
        try {
            DB::select('SELECT pg_sleep(0.5)');
            self::fail('Slow query must retain its statement timeout');
        } catch (QueryException $exception) {
            self::assertSame('57014', $exception->errorInfo[0]);
        }
    }

    public function test_temporary_status_connection_obeys_deadline_and_restores_timeouts_after_cancellation(): void
    {
        $name = 'assistant_status_guard_test';
        $temporary = DB::build([...DB::connection()->getConfig(), 'name' => $name]);
        $temporary->statement("SET statement_timeout = '17s'");
        $this->activate();
        DB::beginTransaction();
        DB::select('SELECT 1');
        (new \ReflectionProperty($this->context, 'deadlineNanoseconds'))->setValue($this->context, hrtime(true) + 500_000_000);
        try {
            try {
                $this->context->withTemporaryDatabaseConnection($temporary, fn () => $temporary->transaction(function () use ($temporary): void {
                    $temporary->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                    $temporary->select('SELECT pg_sleep(2)');
                }));
                self::fail('Temporary read connection must obey the remaining request budget');
            } catch (QueryException $exception) {
                self::assertSame('57014', $exception->errorInfo[0]);
            }
            $this->context->restoreDatabaseStatementTimeouts();
            self::assertSame('17s', $temporary->selectOne('SHOW statement_timeout')->statement_timeout);
            self::assertSame(0, $temporary->transactionLevel());
            self::assertSame(1, DB::transactionLevel());
            self::assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
            DB::statement('INSERT INTO assistant_report_guard_probe (id) VALUES (1)');
        } finally {
            DB::purge($name);
        }
        $this->context->restoreDatabaseStatementTimeouts();
        self::assertArrayNotHasKey($name, DB::getConnections());
    }

    public function test_report_authorization_reuses_lifecycle_transaction_after_its_locked_reads(): void
    {
        $this->activate();
        DB::transaction(function (): void {
            DB::select('SELECT id FROM assistant_report_guard_probe FOR UPDATE');
            $orchestrator = app(ReportHttpAuthorizationOrchestrator::class);
            $method = new \ReflectionMethod($orchestrator, 'transaction');
            $result = $method->invoke($orchestrator, static fn (): array => [
                DB::transactionLevel(), DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
                DB::selectOne('SHOW transaction_read_only')->transaction_read_only,
            ]);
            self::assertSame([1, 'read committed', 'off'], $result);
            DB::statement('INSERT INTO assistant_report_guard_probe (id) VALUES (1)');
        });
        self::assertSame(1, DB::table('assistant_report_guard_probe')->count());
    }

    public function test_standalone_report_authorization_retains_its_readonly_snapshot(): void
    {
        $this->activate();
        $orchestrator = app(ReportHttpAuthorizationOrchestrator::class);
        $method = new \ReflectionMethod($orchestrator, 'transaction');
        $result = $method->invoke($orchestrator, static fn (): array => [
            DB::transactionLevel(), DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
            DB::selectOne('SHOW transaction_read_only')->transaction_read_only,
        ]);
        self::assertSame([1, 'repeatable read', 'on'], $result);
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_native_report_catalog_runs_under_the_real_assistant_operation_guard(): void
    {
        self::assertSame(0, DB::transactionLevel());
        $this->activate();
        $authorizer = (new \ReflectionClass(\App\BusinessModules\Core\Reporting\Infrastructure\Execution\LaravelCurrentReportScopeAuthorizer::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($authorizer, 'transaction');
        $result = $method->invoke($authorizer, static fn (): array => [
            DB::transactionLevel(), DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
            DB::selectOne('SHOW transaction_read_only')->transaction_read_only,
        ], true);
        self::assertSame([1, 'repeatable read', 'on'], $result);
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_native_export_history_runs_under_the_real_assistant_operation_guard(): void
    {
        self::assertSame(0, DB::transactionLevel());
        DB::statement('CREATE TEMPORARY TABLE report_exports (id varchar(26), organization_id bigint, scope_project_ids jsonb)');
        try {
            $this->activate();
            $result = app(\App\BusinessModules\Core\Reporting\Application\Queries\ProjectReportExportHistory::class)->read(1, 1, 1);
            self::assertSame([], $result['items']);
            self::assertTrue($result['complete']);
            self::assertSame(0, DB::transactionLevel());
        } finally {
            $this->context?->restoreDatabaseStatementTimeouts();
            DB::statement('DROP TABLE report_exports');
        }
    }

    private function activate(): void
    {
        $lifecycle = (new \ReflectionClass(AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
        $this->context = new AssistantRequestExecutionContext($lifecycle, new AssistantRequest, new User, 15000);
        app()->instance(AssistantRequestExecutionContext::class, $this->context);
        $this->context->activate();
    }
}
