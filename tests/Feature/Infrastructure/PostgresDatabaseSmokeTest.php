<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PostgresDatabaseSmokeTest extends TestCase
{
    public function refreshDatabase(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        parent::refreshDatabase();
    }

    public function test_laravel_uses_isolated_postgresql_database(): void
    {
        self::assertStringEndsWith('_testing', DB::connection()->getDatabaseName());
        self::assertTrue(Schema::hasTable('migrations'));
    }
}
