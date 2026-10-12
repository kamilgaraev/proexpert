<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

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

    private function activate(): void
    {
        $lifecycle = (new \ReflectionClass(AssistantRequestLifecycle::class))->newInstanceWithoutConstructor();
        $this->context = new AssistantRequestExecutionContext($lifecycle, new AssistantRequest, new User, 15000);
        app()->instance(AssistantRequestExecutionContext::class, $this->context);
        $this->context->activate();
    }
}
