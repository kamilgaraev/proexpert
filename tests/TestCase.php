<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require dirname(__DIR__) . '/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        $this->resetRefreshDatabaseStateWhenCoreSchemaWasRebuilt();

        parent::tearDown();
    }

    public function artisan($command, $parameters = [])
    {
        if ($command !== 'migrate:fresh') {
            return parent::artisan($command, $parameters);
        }

        $outputLevel = ob_get_level();
        ob_start();

        try {
            return $this->app[Kernel::class]->call($command, $parameters);
        } finally {
            while (ob_get_level() > $outputLevel) {
                ob_end_clean();
            }
        }
    }

    private function resetRefreshDatabaseStateWhenCoreSchemaWasRebuilt(): void
    {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                return;
            }

            if (!Schema::hasTable('organizations') || !Schema::hasColumn('organizations', 'legal_name')) {
                RefreshDatabaseState::$migrated = false;
            }
        } catch (\Throwable) {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
