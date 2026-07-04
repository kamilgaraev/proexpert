<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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

    private function resetRefreshDatabaseStateWhenCoreSchemaWasRebuilt(): void
    {
        try {
            if (!Schema::hasTable('organizations') || !Schema::hasColumn('organizations', 'legal_name')) {
                RefreshDatabaseState::$migrated = false;
            }
        } catch (\Throwable) {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
