<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\TestCase;

final class AssistantEstimateExactNameIndexMigrationTest extends TestCase
{
    private mixed $previousApplication = null;

    private mixed $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = DB::getFacadeApplication();
        $this->previousConnection = DB::getFacadeRoot();
        DB::setFacadeApplication(null);
    }

    protected function tearDown(): void
    {
        DB::clearResolvedInstance('db');
        if ($this->previousConnection !== null) {
            DB::swap($this->previousConnection);
        }
        DB::setFacadeApplication($this->previousApplication);
        Mockery::close();
        parent::tearDown();
    }

    public function test_sqlite_up_and_down_skip_all_database_operations(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->twice()->andReturn('sqlite');
        DB::swap($connection);
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_01_120000_add_estimate_exact_name_lookup_index.php';
        self::assertFalse($migration->withinTransaction);
        $migration->up();
        $migration->down();
    }
}
