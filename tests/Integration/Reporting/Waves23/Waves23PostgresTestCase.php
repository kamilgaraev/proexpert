<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

abstract class Waves23PostgresTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_WAVES23_SUPPLY_POSTGRES') !== '1'
            || config('database.default') !== 'pgsql') {
            self::markTestSkipped('Requires explicit migrated disposable PostgreSQL reporting database.');
        }
        $database = (string) DB::connection()->getDatabaseName();
        if (! str_ends_with($database, '_test') && ! str_ends_with($database, '_testing')) {
            self::markTestSkipped('PostgreSQL reporting database must be disposable.');
        }
    }

    protected function assertTriggerExists(string $table, string $trigger): void
    {
        self::assertTrue(DB::table('pg_trigger')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
            ->where('pg_class.relname', $table)
            ->where('pg_trigger.tgname', $trigger)
            ->where('pg_trigger.tgisinternal', false)
            ->exists());
    }

    protected function column(string $table, string $column): object
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->firstOrFail();
    }
}
