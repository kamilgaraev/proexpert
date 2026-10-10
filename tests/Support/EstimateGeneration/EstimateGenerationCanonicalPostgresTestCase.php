<?php

declare(strict_types=1);

namespace Tests\Support\EstimateGeneration;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class EstimateGenerationCanonicalPostgresTestCase extends EstimateGenerationApplicationTestCase
{
    private ?AssistantIndexingState $indexingState = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        self::assertSame('55433', (string) DB::connection()->getConfig('port'));
        self::assertStringEndsWith('_testing', DB::getDatabaseName());
        $this->indexingState = app(AssistantIndexingState::class);
        $this->indexingState->beginMigration();
        if (! Schema::hasTable('organizations')) {
            foreach (EstimateGenerationContractDatabaseProvisioner::freshInventory() as $migrationPath) {
                (require base_path($migrationPath))->up();
            }
        }
        self::assertTrue(Schema::hasColumn('estimate_generation_documents', 'processing_control_status'));
    }

    protected function tearDown(): void
    {
        try {
            $this->indexingState?->endMigration();
        } finally {
            parent::tearDown();
        }
    }
}
