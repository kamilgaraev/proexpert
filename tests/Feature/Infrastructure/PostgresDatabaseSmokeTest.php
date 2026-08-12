<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

final class PostgresDatabaseSmokeTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_laravel_uses_isolated_postgresql_database(): void
    {
        $connection = DB::connection();

        self::assertSame('pgsql', $connection->getDriverName());
        self::assertSame('most_backend_testing', $connection->getDatabaseName());

        $version = DB::selectOne('SELECT version() AS version');

        self::assertIsObject($version);
        self::assertStringStartsWith('PostgreSQL 16.', (string) $version->version);
    }
}
