<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\MigrationRollbackPlan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationRollbackPlanTest extends TestCase
{
    #[Test]
    public function reversible_batch_is_fully_rolled_back(): void
    {
        $plan = MigrationRollbackPlan::forApplied(['a', 'b'], static fn (string $name): bool => false);

        self::assertSame(2, $plan->rollbackSteps);
        self::assertFalse($plan->requiresFixForward);
    }

    #[Test]
    public function mixed_batch_rolls_back_only_reversible_tail_and_preserves_forward_only_prefix(): void
    {
        $plan = MigrationRollbackPlan::forApplied(
            ['reversible-before', 'stage4-000600', 'stage4-000610', 'reversible-tail'],
            static fn (string $name): bool => str_starts_with($name, 'stage4-'),
        );

        self::assertSame(1, $plan->rollbackSteps);
        self::assertTrue($plan->requiresFixForward);
        self::assertSame(['reversible-before', 'stage4-000600', 'stage4-000610'], $plan->preservedMigrations);
    }

    #[Test]
    public function failure_after_forward_only_migration_never_requests_its_rollback(): void
    {
        $plan = MigrationRollbackPlan::forApplied(
            ['stage4-000600', 'stage4-000610'],
            static fn (string $name): bool => true,
        );

        self::assertSame(0, $plan->rollbackSteps);
        self::assertTrue($plan->requiresFixForward);
    }

    #[Test]
    public function migrate_safe_uses_the_machine_marker_and_keeps_failure_exit_for_stage4_retry(): void
    {
        $root = dirname(__DIR__, 3);
        $command = (string) file_get_contents($root.'/app/Console/Commands/MigrateWithRollback.php');
        self::assertStringContainsString('instanceof ForwardOnlyMigration', $command);
        self::assertStringContainsString('$plan->rollbackSteps === 0', $command);
        self::assertStringContainsString('count($plan->preservedMigrations)', $command);
        self::assertStringContainsString('fix-forward and retry are required', $command);
        self::assertStringContainsString('return 1;', $command);

        foreach (['000600', '000610', '000620', '000630'] as $version) {
            $files = glob($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_'.$version.'_*.php');
            self::assertCount(1, $files);
            $migration = (string) file_get_contents($files[0]);
            self::assertStringContainsString('implements ForwardOnlyMigration', $migration);
            self::assertStringContainsString('throw new RuntimeException', $migration);
        }
    }
}
