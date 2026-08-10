<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObsoleteRuntimeStateCleanupMigrationTest extends TestCase
{
    #[Test]
    public function migration_removes_finalization_delivery_and_training_lease_state_only(): void
    {
        $source = (string) file_get_contents($this->migrationPath());

        foreach ([
            "Schema::dropIfExists('estimate_generation_finalization_deliveries')",
            "Schema::dropIfExists('estimate_generation_finalization_outbox')",
            'eg_finalization_delivery_immutable_guard',
            'eg_training_lease_write_fence',
            'eg_training_processing_lease_idx',
            'processing_token',
            'processing_lease_expires_at',
            'processing_attempt',
            "where('status', 'processing')",
            "'status' => 'draft'",
        ] as $removedContract) {
            self::assertStringContainsString($removedContract, $source);
        }

        self::assertStringNotContainsString("Schema::dropIfExists('estimate_generation_training_datasets')", $source);
        self::assertStringNotContainsString("Schema::dropIfExists('estimate_generation_training_examples')", $source);
        self::assertStringNotContainsString("Schema::dropIfExists('estimate_generation_failures')", $source);
        self::assertStringNotContainsString("Schema::dropIfExists('estimate_generation_ai_estimate_quota_reservations')", $source);
        self::assertStringContainsString('throw new \\RuntimeException', $source);
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 4)
            .'/app/BusinessModules/Addons/EstimateGeneration/migrations/'
            .'2026_08_10_000200_drop_obsolete_runtime_state.php';
    }
}
