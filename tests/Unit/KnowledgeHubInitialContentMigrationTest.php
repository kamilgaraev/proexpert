<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class KnowledgeHubInitialContentMigrationTest extends TestCase
{
    public function test_release_migration_reapplies_initial_knowledge_hub_content(): void
    {
        $migrationPath = dirname(__DIR__, 2)
            .'/app/BusinessModules/Features/KnowledgeHub/migrations/2026_07_06_000001_seed_knowledge_hub_initial_content.php';

        self::assertFileExists($migrationPath);

        $migration = (string) file_get_contents($migrationPath);

        self::assertStringContainsString("Artisan::call('knowledge-hub:seed-initial-content')", $migration);
        self::assertStringContainsString('Schema::hasTable(\'knowledge_articles\')', $migration);
        self::assertStringContainsString('Schema::hasTable(\'knowledge_categories\')', $migration);
    }
}
