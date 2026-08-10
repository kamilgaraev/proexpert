<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Migrations;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InternalAiBudgetCleanupMigrationTest extends TestCase
{
    #[Test]
    public function migration_removes_only_internal_budget_accounting(): void
    {
        $source = (string) file_get_contents($this->migrationPath());

        foreach ([
            'eg_reserve_ai_budget',
            'eg_claim_ai_budget_wire',
            'eg_mark_ai_budget_sent',
            'eg_settle_ai_budget',
            'eg_release_ai_budget',
            'eg_mark_ai_budget_reconciliation',
            'eg_reconcile_expired_ai_budgets',
            "Schema::dropIfExists('estimate_generation_ai_budget_reservations')",
        ] as $removedContract) {
            self::assertStringContainsString($removedContract, $source);
        }

        self::assertStringNotContainsString("Schema::dropIfExists('estimate_generation_ai_operations')", $source);
        self::assertStringNotContainsString('eg_pin_ai_operation_settings', $source);
        self::assertStringContainsString('throw new \\RuntimeException', $source);
    }

    private function migrationPath(): string
    {
        return dirname(__DIR__, 4)
            .'/app/BusinessModules/Addons/EstimateGeneration/migrations/'
            .'2026_08_10_000100_drop_internal_ai_budget_accounting.php';
    }
}
